<?php
/**
 * DATABASE MIGRATION SCRIPT
 * Adds missing columns to production database
 * Run this ONCE via browser: https://withlocals.deetech.cc/migrate_production_database.php
 * Date: October 24, 2025
 */

// Load database configuration
require_once 'api/config.php';

// Security: Only allow from specific IP or add a secret key
$MIGRATION_SECRET = 'migrate2025'; // Change this to something secure

// Check if migration is authorized
$authorized = false;
if (isset($_GET['secret']) && $_GET['secret'] === $MIGRATION_SECRET) {
    $authorized = true;
}

// Check if confirmation is provided
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

// Check if rollback is requested
$rollback = isset($_GET['rollback']) && $_GET['rollback'] === 'yes';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - Florence with Locals</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .alert-warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .alert-success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .alert-danger {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .alert-info {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            border: none;
            font-size: 16px;
            margin: 10px 10px 10px 0;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .code {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-missing {
            background: #f8d7da;
            color: #721c24;
        }
        .status-exists {
            background: #d4edda;
            color: #155724;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Database Migration Tool</h1>
            <p>Florence with Locals Tour Guide Management System</p>
        </div>

        <div class="content">
<?php

if (!$authorized) {
    // Show authorization form
    ?>
    <div class="alert alert-warning">
        <strong>⚠️ Authorization Required</strong><br>
        This is a protected migration script. Please enter the migration secret key.
    </div>

    <form method="GET" action="">
        <div style="margin: 20px 0;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Migration Secret Key:</label>
            <input type="text" name="secret" placeholder="Enter secret key" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px;">
        </div>
        <button type="submit" class="btn btn-primary">Unlock Migration</button>
    </form>

    <div class="alert alert-info" style="margin-top: 30px;">
        <strong>📝 Instructions:</strong><br>
        1. The secret key is: <code style="background: #fff; padding: 2px 6px; border-radius: 3px;">migrate2025</code><br>
        2. Enter the key above to proceed with migration
    </div>
    <?php
    exit;
}

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    ?>
    <div class="alert alert-danger">
        <strong>❌ Database Connection Failed</strong><br>
        <?php echo htmlspecialchars($conn->connect_error); ?>
    </div>
    <?php
    exit;
}

// Get current schema
$current_columns = [];
$result = $conn->query("DESCRIBE tours");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $current_columns[] = $row['Field'];
    }
}

// Define columns to add
$columns_to_add = [
    [
        'name' => 'language',
        'definition' => "VARCHAR(50) DEFAULT NULL COMMENT 'Tour language from Bokun API'",
        'after' => 'title',
        'description' => 'Tour language extracted from Bokun API (Viator, GetYourGuide, etc.)'
    ],
    [
        'name' => 'rescheduled',
        'definition' => "TINYINT(1) DEFAULT 0 COMMENT 'Rescheduling flag'",
        'after' => 'cancellation_reason',
        'description' => 'Flag indicating if tour was rescheduled'
    ],
    [
        'name' => 'original_date',
        'definition' => "DATE DEFAULT NULL COMMENT 'Original tour date before rescheduling'",
        'after' => 'rescheduled',
        'description' => 'Original date before tour was rescheduled'
    ],
    [
        'name' => 'original_time',
        'definition' => "TIME DEFAULT NULL COMMENT 'Original tour time before rescheduling'",
        'after' => 'original_date',
        'description' => 'Original time before tour was rescheduled'
    ],
    [
        'name' => 'rescheduled_at',
        'definition' => "TIMESTAMP NULL DEFAULT NULL COMMENT 'When tour was rescheduled'",
        'after' => 'original_time',
        'description' => 'Timestamp when tour was rescheduled'
    ],
    [
        'name' => 'payment_notes',
        'definition' => "TEXT DEFAULT NULL COMMENT 'Payment-related notes'",
        'after' => 'expected_amount',
        'description' => 'Additional payment-related notes'
    ]
];

// Check which columns are missing
$missing_columns = [];
foreach ($columns_to_add as $col) {
    if (!in_array($col['name'], $current_columns)) {
        $missing_columns[] = $col;
    }
}

if ($rollback && $confirmed) {
    // ROLLBACK MODE
    ?>
    <div class="alert alert-warning">
        <strong>🔄 Rolling Back Changes...</strong>
    </div>
    <?php

    $rollback_success = true;
    $rollback_errors = [];

    foreach ($columns_to_add as $col) {
        if (in_array($col['name'], $current_columns)) {
            $sql = "ALTER TABLE tours DROP COLUMN `{$col['name']}`";
            if (!$conn->query($sql)) {
                $rollback_success = false;
                $rollback_errors[] = "Failed to drop {$col['name']}: " . $conn->error;
            }
        }
    }

    if ($rollback_success) {
        ?>
        <div class="alert alert-success">
            <strong>✅ Rollback Completed Successfully!</strong><br>
            All added columns have been removed.
        </div>
        <a href="?secret=<?php echo $MIGRATION_SECRET; ?>" class="btn btn-primary">Check Schema Again</a>
        <?php
    } else {
        ?>
        <div class="alert alert-danger">
            <strong>❌ Rollback Failed</strong><br>
            <?php foreach ($rollback_errors as $error): ?>
                • <?php echo htmlspecialchars($error); ?><br>
            <?php endforeach; ?>
        </div>
        <?php
    }

} elseif (!$confirmed) {
    // SHOW PREVIEW MODE
    ?>
    <div class="alert alert-info">
        <strong>📊 Current Database Status</strong><br>
        Database: <strong><?php echo htmlspecialchars($db_name); ?></strong><br>
        Total Columns in 'tours' table: <strong><?php echo count($current_columns); ?></strong>
    </div>

    <?php if (count($missing_columns) === 0): ?>
        <div class="alert alert-success">
            <strong>✅ Database Schema is Up to Date!</strong><br>
            All required columns already exist. No migration needed.
        </div>

        <h3 style="margin-top: 30px;">All Columns Present:</h3>
        <table>
            <thead>
                <tr>
                    <th>Column Name</th>
                    <th>Status</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns_to_add as $col): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($col['name']); ?></strong></td>
                    <td><span class="status status-exists">✅ EXISTS</span></td>
                    <td><?php echo htmlspecialchars($col['description']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <a href="?secret=<?php echo $MIGRATION_SECRET; ?>&rollback=yes&confirm=yes" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove all these columns? This cannot be undone!')">
            🔄 Rollback (Remove Columns)
        </a>

    <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ Migration Required</strong><br>
            Found <strong><?php echo count($missing_columns); ?></strong> missing column(s) that need to be added.
        </div>

        <h3>Columns to be Added:</h3>
        <table>
            <thead>
                <tr>
                    <th>Column Name</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns_to_add as $col): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($col['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($col['definition']); ?></td>
                    <td><?php echo htmlspecialchars($col['description']); ?></td>
                    <td>
                        <?php if (in_array($col['name'], $current_columns)): ?>
                            <span class="status status-exists">✅ EXISTS</span>
                        <?php else: ?>
                            <span class="status status-missing">❌ MISSING</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-top: 30px;">SQL Commands to be Executed:</h3>
        <div class="code">
<?php foreach ($missing_columns as $col): ?>
ALTER TABLE tours ADD COLUMN <?php echo $col['name']; ?> <?php echo $col['definition']; ?> AFTER <?php echo $col['after']; ?>;<br>
<?php endforeach; ?>
        </div>

        <div class="alert alert-warning" style="margin-top: 20px;">
            <strong>⚠️ Important Notes:</strong><br>
            • This migration will add <?php echo count($missing_columns); ?> new column(s) to the tours table<br>
            • Existing data will NOT be affected<br>
            • New columns will have NULL or default values<br>
            • This operation is reversible (you can rollback if needed)<br>
            • Estimated time: Less than 10 seconds
        </div>

        <a href="?secret=<?php echo $MIGRATION_SECRET; ?>&confirm=yes" class="btn btn-success">
            ✅ Confirm and Run Migration
        </a>
        <a href="/" class="btn btn-danger">
            ❌ Cancel
        </a>
    <?php endif; ?>

<?php
} else {
    // EXECUTE MIGRATION
    ?>
    <div class="alert alert-info">
        <strong>🚀 Running Migration...</strong>
    </div>
    <?php

    $success_count = 0;
    $error_count = 0;
    $errors = [];

    echo "<h3>Migration Progress:</h3>";
    echo "<table>";
    echo "<thead><tr><th>Column</th><th>SQL Command</th><th>Status</th></tr></thead>";
    echo "<tbody>";

    foreach ($missing_columns as $col) {
        $sql = "ALTER TABLE tours ADD COLUMN `{$col['name']}` {$col['definition']} AFTER `{$col['after']}`";

        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($col['name']) . "</strong></td>";
        echo "<td style='font-family: monospace; font-size: 12px;'>" . htmlspecialchars($sql) . "</td>";

        if ($conn->query($sql)) {
            echo "<td><span class='status status-exists'>✅ SUCCESS</span></td>";
            $success_count++;
        } else {
            echo "<td><span class='status status-missing'>❌ FAILED</span></td>";
            $error_count++;
            $errors[] = $col['name'] . ": " . $conn->error;
        }

        echo "</tr>";
    }

    echo "</tbody></table>";

    if ($error_count === 0) {
        ?>
        <div class="alert alert-success">
            <strong>✅ Migration Completed Successfully!</strong><br>
            Added <?php echo $success_count; ?> new column(s) to the tours table.
        </div>

        <h3 style="margin-top: 30px;">✅ Features Now Enabled:</h3>
        <ul style="line-height: 2;">
            <li>🌍 <strong>Language Detection</strong> - Tour language badges will now appear in Tours page</li>
            <li>🔄 <strong>Rescheduling Support</strong> - System can now track and display rescheduled tours</li>
            <li>📝 <strong>Payment Notes</strong> - Additional payment-related notes field available</li>
        </ul>

        <div class="alert alert-info" style="margin-top: 20px;">
            <strong>📋 Next Steps:</strong><br>
            1. Delete this migration file from the server (for security)<br>
            2. Visit <a href="https://withlocals.deetech.cc" target="_blank">https://withlocals.deetech.cc</a><br>
            3. Check Tours page - language badges should now appear<br>
            4. Test all features to ensure everything works
        </div>

        <a href="https://withlocals.deetech.cc" class="btn btn-success">
            🚀 Go to Application
        </a>

        <a href="?secret=<?php echo $MIGRATION_SECRET; ?>" class="btn btn-primary">
            📊 View Updated Schema
        </a>

        <?php
    } else {
        ?>
        <div class="alert alert-danger">
            <strong>❌ Migration Completed with Errors</strong><br>
            Successfully added: <?php echo $success_count; ?> column(s)<br>
            Failed: <?php echo $error_count; ?> column(s)<br><br>
            <strong>Errors:</strong><br>
            <?php foreach ($errors as $error): ?>
                • <?php echo htmlspecialchars($error); ?><br>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

$conn->close();
?>
        </div>

        <div class="footer">
            Database Migration Tool v1.0 • Florence with Locals • October 24, 2025
        </div>
    </div>
</body>
</html>
