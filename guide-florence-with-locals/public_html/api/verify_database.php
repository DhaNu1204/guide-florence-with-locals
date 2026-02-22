<?php
/**
 * Database Structure Verification Script
 * Checks if all required tables and columns exist
 */

// Include database configuration
require_once 'config.php';

// Set headers for JSON response
header('Content-Type: application/json');

// Required tables and their columns
$requiredSchema = [
    'users' => ['id', 'username', 'password', 'email', 'role', 'created_at', 'updated_at'],
    'guides' => ['id', 'name', 'email', 'phone', 'languages', 'notes', 'created_at', 'updated_at'],
    'tours' => [
        'id', 'external_id', 'bokun_booking_id', 'bokun_confirmation_code',
        'title', 'description', 'date', 'time', 'duration',
        'guide_id', 'guide_name',
        'customer_name', 'customer_email', 'customer_phone',
        'participants', 'price', 'paid', 'payment_status', 'payment_method',
        'total_amount_paid', 'expected_amount',
        'cancelled', 'cancellation_reason',
        'rescheduled', 'original_date', 'original_time', 'rescheduled_at',
        'booking_channel', 'booking_reference',
        'external_source', 'needs_guide_assignment',
        'bokun_data', 'last_sync',
        'notes', 'created_at', 'updated_at'
    ],
    'tickets' => ['id', 'museum', 'ticket_type', 'date', 'time', 'quantity', 'price', 'notes', 'status', 'created_at', 'updated_at'],
    'payments' => ['id', 'tour_id', 'guide_id', 'guide_name', 'amount', 'payment_method', 'payment_date', 'payment_time', 'description', 'notes', 'transaction_reference', 'status', 'created_at', 'updated_at'],
    'guide_payments' => ['id', 'guide_id', 'guide_name', 'month', 'total_amount', 'tour_count', 'payment_count', 'created_at', 'updated_at'],
    'sessions' => ['session_id', 'user_id', 'user_data', 'expires_at', 'created_at'],
    'bokun_config' => ['id', 'vendor_id', 'api_key', 'api_secret', 'api_base_url', 'booking_channel', 'last_sync', 'sync_enabled', 'created_at', 'updated_at']
];

$result = [
    'environment' => ENVIRONMENT,
    'database' => $db_name,
    'timestamp' => date('Y-m-d H:i:s'),
    'tables' => [],
    'summary' => [
        'total_tables' => count($requiredSchema),
        'existing_tables' => 0,
        'missing_tables' => 0,
        'total_columns_checked' => 0,
        'missing_columns' => 0
    ],
    'missing_columns_list' => [],
    'missing_tables_list' => [],
    'status' => 'unknown'
];

try {
    // Check each required table
    foreach ($requiredSchema as $tableName => $requiredColumns) {
        $tableInfo = [
            'name' => $tableName,
            'exists' => false,
            'columns' => [],
            'missing_columns' => [],
            'row_count' => 0
        ];

        // Check if table exists
        $checkTable = $conn->query("SHOW TABLES LIKE '$tableName'");

        if ($checkTable && $checkTable->num_rows > 0) {
            $tableInfo['exists'] = true;
            $result['summary']['existing_tables']++;

            // Get actual columns
            $columnsResult = $conn->query("SHOW COLUMNS FROM `$tableName`");
            $actualColumns = [];

            while ($row = $columnsResult->fetch_assoc()) {
                $actualColumns[] = $row['Field'];
                $tableInfo['columns'][] = [
                    'name' => $row['Field'],
                    'type' => $row['Type'],
                    'null' => $row['Null'],
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra']
                ];
            }

            // Check for missing columns
            foreach ($requiredColumns as $requiredColumn) {
                $result['summary']['total_columns_checked']++;

                if (!in_array($requiredColumn, $actualColumns)) {
                    $tableInfo['missing_columns'][] = $requiredColumn;
                    $result['summary']['missing_columns']++;
                    $result['missing_columns_list'][] = "$tableName.$requiredColumn";
                }
            }

            // Get row count
            $countResult = $conn->query("SELECT COUNT(*) as count FROM `$tableName`");
            if ($countResult) {
                $countRow = $countResult->fetch_assoc();
                $tableInfo['row_count'] = (int)$countRow['count'];
            }

        } else {
            $result['summary']['missing_tables']++;
            $result['missing_tables_list'][] = $tableName;
        }

        $result['tables'][$tableName] = $tableInfo;
    }

    // Determine overall status
    if ($result['summary']['missing_tables'] > 0) {
        $result['status'] = 'critical';
        $result['message'] = count($result['missing_tables_list']) . ' critical tables missing';
    } else if ($result['summary']['missing_columns'] > 0) {
        $result['status'] = 'warning';
        $result['message'] = count($result['missing_columns_list']) . ' columns missing (Bokun integration may not work properly)';
    } else {
        $result['status'] = 'success';
        $result['message'] = 'All required tables and columns exist';
    }

    // Generate migration SQL if needed
    if ($result['summary']['missing_columns'] > 0) {
        $migrationSQL = generateMigrationSQL($result['tables']);
        $result['migration_sql'] = $migrationSQL;
    }

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Verification failed',
        'message' => $e->getMessage(),
        'environment' => ENVIRONMENT
    ], JSON_PRETTY_PRINT);
}

function generateMigrationSQL($tables) {
    $sql = "-- Migration SQL to add missing columns\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $tableName => $tableInfo) {
        if (!empty($tableInfo['missing_columns'])) {
            $sql .= "-- Add missing columns to $tableName\n";
            $sql .= "ALTER TABLE `$tableName`\n";

            $columnDefinitions = [
                'external_id' => "ADD COLUMN `external_id` varchar(100) DEFAULT NULL COMMENT 'Bokun confirmation code' AFTER `id`",
                'bokun_booking_id' => "ADD COLUMN `bokun_booking_id` varchar(100) DEFAULT NULL COMMENT 'Bokun internal booking ID' AFTER `external_id`",
                'bokun_confirmation_code' => "ADD COLUMN `bokun_confirmation_code` varchar(100) DEFAULT NULL COMMENT 'Bokun product confirmation code' AFTER `bokun_booking_id`",
                'total_amount_paid' => "ADD COLUMN `total_amount_paid` decimal(10,2) DEFAULT NULL AFTER `payment_method`",
                'expected_amount' => "ADD COLUMN `expected_amount` decimal(10,2) DEFAULT NULL AFTER `total_amount_paid`",
                'rescheduled' => "ADD COLUMN `rescheduled` tinyint(1) DEFAULT 0 COMMENT 'Rescheduling flag' AFTER `cancellation_reason`",
                'original_date' => "ADD COLUMN `original_date` date DEFAULT NULL COMMENT 'Original tour date before rescheduling' AFTER `rescheduled`",
                'original_time' => "ADD COLUMN `original_time` time DEFAULT NULL COMMENT 'Original tour time before rescheduling' AFTER `original_date`",
                'rescheduled_at' => "ADD COLUMN `rescheduled_at` datetime DEFAULT NULL COMMENT 'When tour was rescheduled' AFTER `original_time`",
                'external_source' => "ADD COLUMN `external_source` varchar(50) DEFAULT NULL COMMENT 'Source: bokun, manual, etc' AFTER `booking_reference`",
                'needs_guide_assignment' => "ADD COLUMN `needs_guide_assignment` tinyint(1) DEFAULT 0 AFTER `external_source`",
                'bokun_data' => "ADD COLUMN `bokun_data` json DEFAULT NULL COMMENT 'Complete Bokun response data' AFTER `needs_guide_assignment`",
                'last_sync' => "ADD COLUMN `last_sync` datetime DEFAULT NULL COMMENT 'Last sync from Bokun' AFTER `bokun_data`"
            ];

            $alterStatements = [];
            foreach ($tableInfo['missing_columns'] as $column) {
                if (isset($columnDefinitions[$column])) {
                    $alterStatements[] = "  " . $columnDefinitions[$column];
                }
            }

            if (!empty($alterStatements)) {
                $sql .= implode(",\n", $alterStatements) . ";\n\n";
            }

            // Add indexes
            $sql .= "-- Add indexes for $tableName\n";
            $sql .= "ALTER TABLE `$tableName`\n";
            $sql .= "  ADD UNIQUE KEY IF NOT EXISTS `bokun_booking_id` (`bokun_booking_id`),\n";
            $sql .= "  ADD KEY IF NOT EXISTS `external_source` (`external_source`),\n";
            $sql .= "  ADD KEY IF NOT EXISTS `external_id` (`external_id`),\n";
            $sql .= "  ADD KEY IF NOT EXISTS `cancelled` (`cancelled`),\n";
            $sql .= "  ADD KEY IF NOT EXISTS `rescheduled` (`rescheduled`);\n\n";
        }
    }

    return $sql;
}

$conn->close();
?>
