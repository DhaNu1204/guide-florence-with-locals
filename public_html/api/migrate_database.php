<?php
/**
 * Database Migration Script
 * Adds missing columns and tables to local database
 */

// Include database configuration
require_once 'config.php';

header('Content-Type: application/json');

$migrations = [];
$errors = [];

// Helper function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Helper function to check if index exists
function indexExists($conn, $table, $indexName) {
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    return $result && $result->num_rows > 0;
}

try {
    // Start transaction
    $conn->begin_transaction();

    // ============================================
    // 1. ADD MISSING COLUMNS TO USERS TABLE
    // ============================================
    if (!columnExists($conn, 'users', 'updated_at')) {
        $migrations[] = "Adding updated_at to users table";
        $conn->query("ALTER TABLE `users` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // ============================================
    // 2. ADD MISSING COLUMNS TO GUIDES TABLE
    // ============================================
    $migrations[] = "Updating guides table";
    $conn->query("ALTER TABLE `guides` MODIFY COLUMN `languages` text DEFAULT NULL");

    if (!columnExists($conn, 'guides', 'notes')) {
        $conn->query("ALTER TABLE `guides` ADD COLUMN `notes` text DEFAULT NULL AFTER `languages`");
    }
    if (!columnExists($conn, 'guides', 'updated_at')) {
        $conn->query("ALTER TABLE `guides` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // ============================================
    // 3. ADD MISSING COLUMNS TO TOURS TABLE
    // ============================================
    $migrations[] = "Adding missing columns to tours table";

    if (!columnExists($conn, 'tours', 'guide_name')) {
        $conn->query("ALTER TABLE `tours` ADD COLUMN `guide_name` varchar(100) DEFAULT NULL AFTER `guide_id`");
    }
    if (!columnExists($conn, 'tours', 'price')) {
        $conn->query("ALTER TABLE `tours` ADD COLUMN `price` decimal(10,2) DEFAULT NULL AFTER `participants`");
    }
    if (!columnExists($conn, 'tours', 'payment_method')) {
        $conn->query("ALTER TABLE `tours` ADD COLUMN `payment_method` varchar(50) DEFAULT NULL AFTER `payment_status`");
    }
    if (!columnExists($conn, 'tours', 'cancellation_reason')) {
        $conn->query("ALTER TABLE `tours` ADD COLUMN `cancellation_reason` text DEFAULT NULL AFTER `cancelled`");
    }
    if (!columnExists($conn, 'tours', 'booking_reference')) {
        $conn->query("ALTER TABLE `tours` ADD COLUMN `booking_reference` varchar(100) DEFAULT NULL AFTER `booking_channel`");
    }

    // ============================================
    // 4. FIX TICKETS TABLE SCHEMA
    // ============================================
    $migrations[] = "Updating tickets table schema";

    // Check if old columns exist and rename/add new ones
    $ticketsColumns = $conn->query("SHOW COLUMNS FROM `tickets`");
    $existingColumns = [];
    while ($col = $ticketsColumns->fetch_assoc()) {
        $existingColumns[] = $col['Field'];
    }

    if (in_array('location', $existingColumns)) {
        $conn->query("ALTER TABLE `tickets` CHANGE COLUMN `location` `museum` varchar(100) NOT NULL");
    }
    if (in_array('code', $existingColumns)) {
        $conn->query("ALTER TABLE `tickets` CHANGE COLUMN `code` `ticket_type` varchar(100) NOT NULL");
    }

    if (!columnExists($conn, 'tickets', 'price')) {
        $conn->query("ALTER TABLE `tickets` ADD COLUMN `price` decimal(10,2) DEFAULT NULL AFTER `quantity`");
    }
    if (!columnExists($conn, 'tickets', 'notes')) {
        $conn->query("ALTER TABLE `tickets` ADD COLUMN `notes` text DEFAULT NULL AFTER `price`");
    }
    if (!columnExists($conn, 'tickets', 'status')) {
        $conn->query("ALTER TABLE `tickets` ADD COLUMN `status` enum('available','reserved','used','expired') DEFAULT 'available' AFTER `notes`");
    }

    // ============================================
    // 5. FIX SESSIONS TABLE
    // ============================================
    $migrations[] = "Updating sessions table";

    if (!columnExists($conn, 'sessions', 'session_id')) {
        $conn->query("ALTER TABLE `sessions` ADD COLUMN `session_id` varchar(128) NOT NULL FIRST");
    }
    if (!columnExists($conn, 'sessions', 'user_data')) {
        $conn->query("ALTER TABLE `sessions` ADD COLUMN `user_data` text DEFAULT NULL AFTER `user_id`");
    }

    // ============================================
    // 6. FIX BOKUN_CONFIG TABLE
    // ============================================
    $migrations[] = "Updating bokun_config table";

    // Get existing column names
    $bokunColumns = $conn->query("SHOW COLUMNS FROM `bokun_config`");
    $existingBokunColumns = [];
    while ($col = $bokunColumns->fetch_assoc()) {
        $existingBokunColumns[] = $col['Field'];
    }

    // Rename old columns if they exist
    if (in_array('access_key', $existingBokunColumns)) {
        $conn->query("ALTER TABLE `bokun_config` CHANGE COLUMN `access_key` `api_key` varchar(255) NOT NULL");
    }
    if (in_array('secret_key', $existingBokunColumns)) {
        $conn->query("ALTER TABLE `bokun_config` CHANGE COLUMN `secret_key` `api_secret` varchar(255) DEFAULT NULL");
    }

    if (!columnExists($conn, 'bokun_config', 'api_base_url')) {
        $conn->query("ALTER TABLE `bokun_config` ADD COLUMN `api_base_url` varchar(255) NOT NULL DEFAULT 'https://api.bokun.is' AFTER `api_secret`");
    }
    if (!columnExists($conn, 'bokun_config', 'booking_channel')) {
        $conn->query("ALTER TABLE `bokun_config` ADD COLUMN `booking_channel` varchar(100) DEFAULT NULL AFTER `api_base_url`");
    }

    // Rename last_sync_date to last_sync
    if (in_array('last_sync_date', $existingBokunColumns)) {
        $conn->query("ALTER TABLE `bokun_config` CHANGE COLUMN `last_sync_date` `last_sync` timestamp NULL DEFAULT NULL");
    } else if (!columnExists($conn, 'bokun_config', 'last_sync')) {
        $conn->query("ALTER TABLE `bokun_config` ADD COLUMN `last_sync` timestamp NULL DEFAULT NULL AFTER `booking_channel`");
    }

    // ============================================
    // 7. CREATE PAYMENTS TABLE
    // ============================================
    $migrations[] = "Creating payments table";
    $conn->query("CREATE TABLE IF NOT EXISTS `payments` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `tour_id` int(11) DEFAULT NULL,
      `guide_id` int(11) DEFAULT NULL,
      `guide_name` varchar(100) DEFAULT NULL,
      `amount` decimal(10,2) NOT NULL,
      `payment_method` varchar(50) DEFAULT NULL,
      `payment_date` date NOT NULL,
      `payment_time` time DEFAULT NULL,
      `description` text DEFAULT NULL,
      `notes` text DEFAULT NULL,
      `transaction_reference` varchar(100) DEFAULT NULL,
      `status` enum('pending','completed','failed','refunded') DEFAULT 'completed',
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `tour_id` (`tour_id`),
      KEY `guide_id` (`guide_id`),
      KEY `payment_date` (`payment_date`),
      KEY `status` (`status`),
      CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE SET NULL,
      CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`guide_id`) REFERENCES `guides` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ============================================
    // 8. CREATE GUIDE_PAYMENTS TABLE
    // ============================================
    $migrations[] = "Creating guide_payments table";
    $conn->query("CREATE TABLE IF NOT EXISTS `guide_payments` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `guide_id` int(11) NOT NULL,
      `guide_name` varchar(100) NOT NULL,
      `month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
      `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
      `tour_count` int(11) NOT NULL DEFAULT 0,
      `payment_count` int(11) NOT NULL DEFAULT 0,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `guide_month` (`guide_id`, `month`),
      KEY `month` (`month`),
      CONSTRAINT `guide_payments_ibfk_1` FOREIGN KEY (`guide_id`) REFERENCES `guides` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ============================================
    // 9. ADD INDEXES
    // ============================================
    $migrations[] = "Adding performance indexes";

    // Add indexes to tours table if they don't exist
    if (!indexExists($conn, 'tours', 'idx_tours_date_guide')) {
        $conn->query("CREATE INDEX idx_tours_date_guide ON tours(date, guide_id)");
    }
    if (!indexExists($conn, 'tours', 'idx_tours_booking_channel')) {
        $conn->query("CREATE INDEX idx_tours_booking_channel ON tours(booking_channel)");
    }

    // Add indexes to payments table if they don't exist
    if (!indexExists($conn, 'payments', 'idx_payments_date_guide')) {
        $conn->query("CREATE INDEX idx_payments_date_guide ON payments(payment_date, guide_id)");
    }

    // Add indexes to tickets table if they don't exist
    if (!indexExists($conn, 'tickets', 'idx_tickets_museum_date')) {
        $conn->query("CREATE INDEX idx_tickets_museum_date ON tickets(museum, date)");
    }

    // Commit transaction
    $conn->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Database migration completed successfully',
        'migrations' => $migrations,
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => ENVIRONMENT
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();

    echo json_encode([
        'success' => false,
        'error' => 'Migration failed: ' . $e->getMessage(),
        'migrations_completed' => $migrations,
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => ENVIRONMENT
    ], JSON_PRETTY_PRINT);
}

$conn->close();
?>
