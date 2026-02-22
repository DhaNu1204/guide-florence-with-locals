<?php
/**
 * Tour Groups API Endpoint
 *
 * Groups individual Bokun bookings that belong to the same tour departure
 * (same product name + date + time). Max group size: 9 PAX (Uffizi rule).
 *
 * Endpoints:
 * GET    /api/tour-groups.php                        - List all groups (with filters)
 * GET    /api/tour-groups.php/{id}                   - Get single group with its tours
 * POST   /api/tour-groups.php?action=auto-group      - Auto-group tours by product+date+time
 * POST   /api/tour-groups.php?action=manual-merge    - Manually merge specific tours
 * POST   /api/tour-groups.php?action=unmerge         - Remove a tour from its group
 * PUT    /api/tour-groups.php/{id}                   - Update group details
 * DELETE /api/tour-groups.php/{id}                   - Dissolve a group
 */

require_once 'config.php';

// Apply rate limiting
autoRateLimit('tour_groups');

// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Ensure tour_groups table exists
ensureTourGroupsTable($conn);

// Get request method and parse URL
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Extract group ID from URL path
$groupId = null;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathSegments = explode('/', $path);
$lastSegment = end($pathSegments);
if (is_numeric($lastSegment)) {
    $groupId = intval($lastSegment);
}

// Route request
switch ($method) {
    case 'GET':
        if ($groupId) {
            getGroupById($conn, $groupId);
        } else {
            listGroups($conn);
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        switch ($action) {
            case 'auto-group':
                autoGroupTours($conn, $data);
                break;
            case 'manual-merge':
                manualMergeTours($conn, $data);
                break;
            case 'unmerge':
                unmergeTour($conn, $data);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: auto-group, manual-merge, or unmerge']);
        }
        break;

    case 'PUT':
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'Group ID is required for update']);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        updateGroup($conn, $groupId, $data);
        break;

    case 'DELETE':
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'Group ID is required for deletion']);
            break;
        }
        dissolveGroup($conn, $groupId);
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

$conn->close();

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * Ensure tour_groups table and group_id column exist
 */
function ensureTourGroupsTable($conn) {
    // Check if tour_groups table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'tour_groups'");
    if ($tableCheck->num_rows === 0) {
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

    // Check if group_id column exists in tours
    $colCheck = $conn->query("SHOW COLUMNS FROM tours LIKE 'group_id'");
    if ($colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE tours ADD COLUMN `group_id` int(11) DEFAULT NULL AFTER `notes`");
        $conn->query("ALTER TABLE tours ADD KEY `idx_tours_group_id` (`group_id`)");
    }
}

/**
 * GET - List all groups with pagination and filters
 */
function listGroups($conn) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 50;
    $offset = ($page - 1) * $perPage;

    // Optional filters
    $filterDate = $_GET['date'] ?? null;
    $guideId = isset($_GET['guide_id']) ? intval($_GET['guide_id']) : null;
    $upcoming = isset($_GET['upcoming']) && $_GET['upcoming'] === 'true';

    // Build WHERE clause
    $where = [];
    $params = [];
    $types = '';

    if ($upcoming) {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+60 days'));
        $where[] = 'tg.group_date >= ?';
        $where[] = 'tg.group_date <= ?';
        $params[] = $today;
        $params[] = $endDate;
        $types .= 'ss';
    } elseif ($filterDate) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
            $where[] = 'tg.group_date = ?';
            $params[] = $filterDate;
            $types .= 's';
        }
    }

    if ($guideId) {
        $where[] = 'tg.guide_id = ?';
        $params[] = $guideId;
        $types .= 'i';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $countSql = "SELECT COUNT(*) as total FROM tour_groups tg $whereClause";
    if (count($params) > 0) {
        $countStmt = $conn->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalRecords = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
    } else {
        $totalRecords = $conn->query($countSql)->fetch_assoc()['total'];
    }

    // Fetch groups
    $sql = "SELECT tg.*, g.name as assigned_guide_name
            FROM tour_groups tg
            LEFT JOIN guides g ON tg.guide_id = g.id
            $whereClause
            ORDER BY tg.group_date ASC, tg.group_time ASC
            LIMIT ? OFFSET ?";

    $allParams = array_merge($params, [$perPage, $offset]);
    $allTypes = $types . 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $groups = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = intval($row['id']);
        $row['max_pax'] = intval($row['max_pax']);
        $row['total_pax'] = intval($row['total_pax']);
        $row['is_manual_merge'] = (bool)$row['is_manual_merge'];
        if ($row['guide_id']) $row['guide_id'] = intval($row['guide_id']);

        // Fetch tours belonging to this group
        $row['tours'] = getGroupTours($conn, $row['id']);
        $row['booking_count'] = count($row['tours']);

        $groups[] = $row;
    }
    $stmt->close();

    $totalPages = ceil($totalRecords / $perPage);

    echo json_encode([
        'data' => $groups,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => intval($totalRecords),
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ]);
}

/**
 * GET - Get a single group by ID with its tours
 */
function getGroupById($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT tg.*, g.name as assigned_guide_name
        FROM tour_groups tg
        LEFT JOIN guides g ON tg.guide_id = g.id
        WHERE tg.id = ?
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) {
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }

    $group['id'] = intval($group['id']);
    $group['max_pax'] = intval($group['max_pax']);
    $group['total_pax'] = intval($group['total_pax']);
    $group['is_manual_merge'] = (bool)$group['is_manual_merge'];
    if ($group['guide_id']) $group['guide_id'] = intval($group['guide_id']);

    // Fetch tours in this group
    $group['tours'] = getGroupTours($conn, $groupId);
    $group['booking_count'] = count($group['tours']);

    echo json_encode(['success' => true, 'data' => $group]);
}

/**
 * Helper: Get tours belonging to a group
 */
function getGroupTours($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT t.id, t.title, t.date, t.time, t.customer_name, t.customer_email,
               t.participants, t.booking_channel, t.bokun_confirmation_code,
               t.cancelled, t.payment_status, t.guide_id, g.name as guide_name
        FROM tours t
        LEFT JOIN guides g ON t.guide_id = g.id
        WHERE t.group_id = ?
        ORDER BY t.created_at ASC
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $result = $stmt->get_result();

    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = intval($row['id']);
        $row['participants'] = intval($row['participants']);
        $row['cancelled'] = (bool)$row['cancelled'];
        if ($row['guide_id']) $row['guide_id'] = intval($row['guide_id']);
        $tours[] = $row;
    }
    $stmt->close();
    return $tours;
}

/**
 * POST action=auto-group - Automatically group tours by product name + date + time
 *
 * Request body:
 *   start_date (optional): YYYY-MM-DD, defaults to today
 *   end_date (optional): YYYY-MM-DD, defaults to +60 days
 *   force (optional): if true, regroup even tours in existing auto-groups
 */
function autoGroupTours($conn, $data) {
    $startDate = $data['start_date'] ?? date('Y-m-d');
    $endDate = $data['end_date'] ?? date('Y-m-d', strtotime('+60 days'));
    $force = isset($data['force']) && $data['force'];

    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        return;
    }

    // Acquire advisory lock to prevent concurrent auto-grouping
    $lockResult = $conn->query("SELECT GET_LOCK('auto_group', 10) as locked");
    $lockRow = $lockResult->fetch_assoc();
    if (!$lockRow || !$lockRow['locked']) {
        http_response_code(409);
        echo json_encode(['error' => 'Auto-grouping is already in progress. Please try again later.']);
        return;
    }

    // Find ungrouped tours (or all non-manual tours if force=true)
    // NEVER touch tours in manually merged groups
    $sql = "SELECT t.id, t.title, t.date, t.time, t.participants, t.cancelled, t.group_id
            FROM tours t
            LEFT JOIN tour_groups tg ON t.group_id = tg.id
            WHERE t.date >= ? AND t.date <= ?
              AND t.cancelled = 0
              AND (
                  t.group_id IS NULL
                  " . ($force ? "OR (tg.is_manual_merge = 0)" : "") . "
              )
              AND (tg.is_manual_merge IS NULL OR tg.is_manual_merge = 0)
            ORDER BY t.title, t.date, t.time";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    $stmt->close();

    if (count($tours) === 0) {
        $conn->query("SELECT RELEASE_LOCK('auto_group')");
        echo json_encode([
            'success' => true,
            'message' => 'No ungrouped tours found in date range',
            'groups_created' => 0,
            'tours_grouped' => 0
        ]);
        return;
    }

    // Group tours by normalized title + date + time
    $buckets = [];
    foreach ($tours as $tour) {
        $key = normalizeTitle($tour['title']) . '|' . $tour['date'] . '|' . normalizeTime($tour['time']);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [];
        }
        $buckets[$key][] = $tour;
    }

    $groupsCreated = 0;
    $groupsUpdated = 0;
    $toursGrouped = 0;

    $conn->begin_transaction();
    try {

    foreach ($buckets as $key => $bucketTours) {
        // Only group if there are 2+ tours, or if a tour is already in a group we're refreshing
        if (count($bucketTours) < 2 && !$force) {
            continue;
        }

        // Split into sub-groups if total PAX > 9
        $subGroups = splitByMaxPax($bucketTours, 9);

        foreach ($subGroups as $subGroup) {
            // Skip sub-groups with only 1 tour (can't form a group)
            if (count($subGroup) < 2) {
                continue;
            }
            $totalPax = array_sum(array_column($subGroup, 'participants'));
            $firstTour = $subGroup[0];

            // Check if there's already an auto-group for these tours
            $existingGroupId = null;
            foreach ($subGroup as $t) {
                if ($t['group_id']) {
                    $existingGroupId = intval($t['group_id']);
                    break;
                }
            }

            if ($existingGroupId && $force) {
                // Update existing group
                $updateStmt = $conn->prepare("
                    UPDATE tour_groups
                    SET total_pax = ?, updated_at = NOW()
                    WHERE id = ? AND is_manual_merge = 0
                ");
                $updateStmt->bind_param('ii', $totalPax, $existingGroupId);
                $updateStmt->execute();
                $updateStmt->close();

                // Re-assign tours to this group
                $tourIds = array_column($subGroup, 'id');
                assignToursToGroup($conn, $tourIds, $existingGroupId);
                $groupsUpdated++;
                $toursGrouped += count($subGroup);
            } else {
                // Create new group
                $displayName = $firstTour['title'];
                $groupDate = $firstTour['date'];
                $groupTime = $firstTour['time'];

                $insertStmt = $conn->prepare("
                    INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge)
                    VALUES (?, ?, ?, ?, 0)
                ");
                $insertStmt->bind_param('sssi', $groupDate, $groupTime, $displayName, $totalPax);

                if ($insertStmt->execute()) {
                    $newGroupId = $conn->insert_id;
                    $insertStmt->close();

                    // Assign tours to the new group
                    $tourIds = array_column($subGroup, 'id');
                    assignToursToGroup($conn, $tourIds, $newGroupId);

                    // Copy guide assignment from first tour that has one
                    syncGroupGuideFromTours($conn, $newGroupId);

                    $groupsCreated++;
                    $toursGrouped += count($subGroup);
                } else {
                    $insertStmt->close();
                    error_log("Failed to create tour group: " . $conn->error);
                }
            }
        }
    }

    // Clean up orphaned groups (no tours reference them)
    $conn->query("DELETE FROM tour_groups WHERE id NOT IN (SELECT DISTINCT group_id FROM tours WHERE group_id IS NOT NULL)");

    $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SELECT RELEASE_LOCK('auto_group')");
        http_response_code(500);
        echo json_encode(['error' => 'Auto-grouping failed: ' . $e->getMessage()]);
        return;
    }

    $conn->query("SELECT RELEASE_LOCK('auto_group')");

    echo json_encode([
        'success' => true,
        'groups_created' => $groupsCreated,
        'groups_updated' => $groupsUpdated,
        'tours_grouped' => $toursGrouped,
        'date_range' => ['start' => $startDate, 'end' => $endDate]
    ]);
}

/**
 * POST action=manual-merge - Manually merge specific tour IDs into a group
 *
 * Request body:
 *   tour_ids (required): array of tour IDs to merge
 *   display_name (optional): custom name for the group
 *   notes (optional): notes for the group
 */
function manualMergeTours($conn, $data) {
    $tourIds = $data['tour_ids'] ?? [];
    $displayName = $data['display_name'] ?? null;
    $notes = $data['notes'] ?? null;

    if (!is_array($tourIds) || count($tourIds) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'At least 2 tour IDs are required for merging']);
        return;
    }

    // Validate all tour IDs exist and get their data
    $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
    $types = str_repeat('i', count($tourIds));

    $stmt = $conn->prepare("SELECT id, title, date, time, participants, group_id FROM tours WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$tourIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $tours = [];
    while ($row = $result->fetch_assoc()) {
        $tours[] = $row;
    }
    $stmt->close();

    if (count($tours) !== count($tourIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'One or more tour IDs not found']);
        return;
    }

    // Calculate total PAX
    $totalPax = array_sum(array_column($tours, 'participants'));

    // Check PAX limit
    if ($totalPax > 9) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Total participants (' . $totalPax . ') exceeds maximum group size of 9',
            'total_pax' => $totalPax,
            'max_pax' => 9
        ]);
        return;
    }

    $conn->begin_transaction();
    try {
        // Remove tours from any existing groups first
        foreach ($tours as $tour) {
            if ($tour['group_id']) {
                removeTourFromGroup($conn, intval($tour['id']), intval($tour['group_id']));
            }
        }

        // Use first tour's data for group defaults
        $firstTour = $tours[0];
        $groupDate = $firstTour['date'];
        $groupTime = $firstTour['time'];
        if (!$displayName) {
            $displayName = $firstTour['title'];
        }

        // Create the group as manual merge
        $stmt = $conn->prepare("
            INSERT INTO tour_groups (group_date, group_time, display_name, notes, total_pax, is_manual_merge)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param('ssssi', $groupDate, $groupTime, $displayName, $notes, $totalPax);

        if (!$stmt->execute()) {
            throw new Exception('Failed to create group: ' . $stmt->error);
        }

        $newGroupId = $conn->insert_id;
        $stmt->close();

        // Assign all tours to the group
        assignToursToGroup($conn, $tourIds, $newGroupId);

        // Sync guide from tours
        syncGroupGuideFromTours($conn, $newGroupId);

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    // Fetch the created group with tours
    $group = fetchGroupWithTours($conn, $newGroupId);

    echo json_encode([
        'success' => true,
        'message' => 'Tours merged successfully',
        'group' => $group
    ]);
}

/**
 * POST action=unmerge - Remove a tour from its group
 *
 * Request body:
 *   tour_id (required): the tour ID to remove from its group
 */
function unmergeTour($conn, $data) {
    $tourId = isset($data['tour_id']) ? intval($data['tour_id']) : 0;

    if (!$tourId) {
        http_response_code(400);
        echo json_encode(['error' => 'tour_id is required']);
        return;
    }

    // Get current tour and its group
    $stmt = $conn->prepare("SELECT id, group_id, participants FROM tours WHERE id = ?");
    $stmt->bind_param('i', $tourId);
    $stmt->execute();
    $tour = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tour) {
        http_response_code(404);
        echo json_encode(['error' => 'Tour not found']);
        return;
    }

    if (!$tour['group_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Tour is not in any group']);
        return;
    }

    $groupId = intval($tour['group_id']);

    $conn->begin_transaction();
    try {
        removeTourFromGroup($conn, $tourId, $groupId);
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to unmerge tour: ' . $e->getMessage()]);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tour removed from group',
        'tour_id' => $tourId,
        'group_id' => $groupId
    ]);
}

/**
 * PUT - Update group details (display_name, notes, guide_id, max_pax)
 */
function updateGroup($conn, $groupId, $data) {
    // Verify group exists
    $checkStmt = $conn->prepare("SELECT id FROM tour_groups WHERE id = ?");
    $checkStmt->bind_param('i', $groupId);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        $checkStmt->close();
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }
    $checkStmt->close();

    // Build dynamic update
    $setFields = [];
    $bindTypes = '';
    $bindValues = [];

    if (isset($data['display_name'])) {
        $setFields[] = 'display_name = ?';
        $bindTypes .= 's';
        $bindValues[] = $data['display_name'];
    }

    if (isset($data['notes'])) {
        $setFields[] = 'notes = ?';
        $bindTypes .= 's';
        $bindValues[] = $data['notes'];
    }

    if (isset($data['guide_id'])) {
        $guideId = $data['guide_id'] === null || $data['guide_id'] === '' ? null : intval($data['guide_id']);
        $setFields[] = 'guide_id = ?';
        $bindTypes .= 'i';
        $bindValues[] = $guideId;

        // Also update guide_name
        if ($guideId) {
            $guideStmt = $conn->prepare("SELECT name FROM guides WHERE id = ?");
            $guideStmt->bind_param('i', $guideId);
            $guideStmt->execute();
            $guideRow = $guideStmt->get_result()->fetch_assoc();
            $guideStmt->close();

            $setFields[] = 'guide_name = ?';
            $bindTypes .= 's';
            $bindValues[] = $guideRow ? $guideRow['name'] : null;
        } else {
            $setFields[] = 'guide_name = NULL';
        }

        // Guide propagation will happen inside the transaction below
    }

    if (isset($data['max_pax'])) {
        $maxPax = max(1, intval($data['max_pax']));
        $setFields[] = 'max_pax = ?';
        $bindTypes .= 'i';
        $bindValues[] = $maxPax;
    }

    if (empty($setFields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $setFields[] = 'updated_at = NOW()';
    $bindTypes .= 'i';
    $bindValues[] = $groupId;

    $conn->begin_transaction();
    try {
        $sql = "UPDATE tour_groups SET " . implode(', ', $setFields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($bindTypes, ...$bindValues);

        if (!$stmt->execute()) {
            throw new Exception('Failed to update group: ' . $stmt->error);
        }
        $stmt->close();

        // Propagate guide to all tours in the group (inside transaction)
        if (isset($data['guide_id'])) {
            $guideIdVal = $data['guide_id'] === null || $data['guide_id'] === '' ? null : intval($data['guide_id']);
            propagateGuideToTours($conn, $groupId, $guideIdVal);
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    $group = fetchGroupWithTours($conn, $groupId);
    echo json_encode(['success' => true, 'data' => $group]);
}

/**
 * DELETE - Dissolve a group (remove group, set tours.group_id = NULL)
 */
function dissolveGroup($conn, $groupId) {
    // Verify group exists
    $checkStmt = $conn->prepare("SELECT id FROM tour_groups WHERE id = ?");
    $checkStmt->bind_param('i', $groupId);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        $checkStmt->close();
        http_response_code(404);
        echo json_encode(['error' => 'Group not found']);
        return;
    }
    $checkStmt->close();

    $conn->begin_transaction();
    try {
        // Remove group_id from all tours in this group
        $stmt = $conn->prepare("UPDATE tours SET group_id = NULL WHERE group_id = ?");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $toursAffected = $stmt->affected_rows;
        $stmt->close();

        // Delete the group
        $stmt = $conn->prepare("DELETE FROM tour_groups WHERE id = ?");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to dissolve group: ' . $e->getMessage()]);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Group dissolved',
        'group_id' => $groupId,
        'tours_ungrouped' => $toursAffected
    ]);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Normalize tour title for grouping (removes extra whitespace, lowercases)
 */
function normalizeTitle($title) {
    return strtolower(trim(preg_replace('/\s+/', ' ', $title)));
}

/**
 * Normalize time for grouping (HH:MM format, strips seconds)
 */
function normalizeTime($time) {
    $parts = explode(':', $time);
    return sprintf('%02d:%02d', intval($parts[0]), intval($parts[1] ?? 0));
}

/**
 * Split tours into sub-groups where each sub-group's total PAX <= maxPax
 */
function splitByMaxPax($tours, $maxPax) {
    $subGroups = [];
    $current = [];
    $currentPax = 0;

    foreach ($tours as $tour) {
        $pax = intval($tour['participants']);

        if ($currentPax + $pax > $maxPax && count($current) > 0) {
            $subGroups[] = $current;
            $current = [];
            $currentPax = 0;
        }

        $current[] = $tour;
        $currentPax += $pax;
    }

    if (count($current) > 0) {
        $subGroups[] = $current;
    }

    return $subGroups;
}

/**
 * Assign tours to a group (update tours.group_id)
 */
function assignToursToGroup($conn, $tourIds, $groupId) {
    if (empty($tourIds)) return;

    $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
    $types = str_repeat('i', count($tourIds));

    $sql = "UPDATE tours SET group_id = ? WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);

    $allParams = array_merge([$groupId], $tourIds);
    $allTypes = 'i' . $types;
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $stmt->close();
}

/**
 * Remove a tour from its group, update PAX, and clean up empty groups
 */
function removeTourFromGroup($conn, $tourId, $groupId) {
    // Get tour's participant count
    $stmt = $conn->prepare("SELECT participants FROM tours WHERE id = ?");
    $stmt->bind_param('i', $tourId);
    $stmt->execute();
    $tour = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $pax = intval($tour['participants'] ?? 0);

    // Remove tour from group
    $stmt = $conn->prepare("UPDATE tours SET group_id = NULL WHERE id = ?");
    $stmt->bind_param('i', $tourId);
    $stmt->execute();
    $stmt->close();

    // Update group's total_pax
    $stmt = $conn->prepare("UPDATE tour_groups SET total_pax = GREATEST(0, total_pax - ?), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $pax, $groupId);
    $stmt->execute();
    $stmt->close();

    // Check if group is now empty or has only 1 tour
    $stmt = $conn->prepare("SELECT COUNT(*) as remaining FROM tours WHERE group_id = ?");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $remaining = $stmt->get_result()->fetch_assoc()['remaining'];
    $stmt->close();

    if ($remaining <= 1) {
        // If only 1 tour left, ungroup it too and delete the group
        $stmt = $conn->prepare("UPDATE tours SET group_id = NULL WHERE group_id = ?");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM tour_groups WHERE id = ?");
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Sync group's guide from its tours (first tour with a guide)
 */
function syncGroupGuideFromTours($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT t.guide_id, g.name as guide_name
        FROM tours t
        LEFT JOIN guides g ON t.guide_id = g.id
        WHERE t.group_id = ? AND t.guide_id IS NOT NULL
        LIMIT 1
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $updateStmt = $conn->prepare("UPDATE tour_groups SET guide_id = ?, guide_name = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->bind_param('isi', $row['guide_id'], $row['guide_name'], $groupId);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

/**
 * Propagate guide assignment from group to all its tours
 */
function propagateGuideToTours($conn, $groupId, $guideId) {
    if ($guideId) {
        $stmt = $conn->prepare("UPDATE tours SET guide_id = ?, needs_guide_assignment = 0, updated_at = NOW() WHERE group_id = ?");
        $stmt->bind_param('ii', $guideId, $groupId);
    } else {
        $stmt = $conn->prepare("UPDATE tours SET guide_id = NULL, needs_guide_assignment = 1, updated_at = NOW() WHERE group_id = ?");
        $stmt->bind_param('i', $groupId);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Fetch a group with its tours (for responses)
 */
function fetchGroupWithTours($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT tg.*, g.name as assigned_guide_name
        FROM tour_groups tg
        LEFT JOIN guides g ON tg.guide_id = g.id
        WHERE tg.id = ?
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) return null;

    $group['id'] = intval($group['id']);
    $group['max_pax'] = intval($group['max_pax']);
    $group['total_pax'] = intval($group['total_pax']);
    $group['is_manual_merge'] = (bool)$group['is_manual_merge'];
    if ($group['guide_id']) $group['guide_id'] = intval($group['guide_id']);

    $group['tours'] = getGroupTours($conn, $groupId);
    $group['booking_count'] = count($group['tours']);

    return $group;
}

/**
 * Recalculate and update the total_pax for a group
 */
function recalculateGroupPax($conn, $groupId) {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(participants), 0) as total_pax
        FROM tours
        WHERE group_id = ? AND cancelled = 0
    ");
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $totalPax = intval($stmt->get_result()->fetch_assoc()['total_pax']);
    $stmt->close();

    $updateStmt = $conn->prepare("UPDATE tour_groups SET total_pax = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->bind_param('ii', $totalPax, $groupId);
    $updateStmt->execute();
    $updateStmt->close();

    return $totalPax;
}
?>
