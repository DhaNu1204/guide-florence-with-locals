<?php
/**
 * Step 7.2: departure queries shared by the screens and the assistant.
 *
 * fwlUnassignedReport() is the step 3.5 unassigned-departures report, moved here UNCHANGED from
 * tours.php (same SQL, same rows) so tours.php?action=unassigned-report and the assistant's
 * unassigned_departures tool run the very same query and can never disagree.
 *
 * fwlDepartureUnits() is the same per-departure (tour unit) aggregation without the "no guide"
 * HAVING, used by the assistant's find_departures / guide_schedule / free_guides.
 */

if (!function_exists('fwlUnassignedReport')) {
    /**
     * @param mysqli $conn
     * @param array  $reportConditions WHERE conditions (the caller adds "t.cancelled = 0")
     * @param array  $reportParams     bound values, in order
     * @param string $reportTypes      bind_param types
     * @return array one row per departure with no effective guide
     */
    function fwlUnassignedReport($conn, array $reportConditions, array $reportParams, $reportTypes) {
        $reportWhere = "WHERE " . implode(" AND ", $reportConditions);

        $reportSql = "SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit,
                             COALESCE(MAX(tg.group_date), MIN(t.date)) AS unit_date,
                             LEFT(COALESCE(MAX(tg.departure_time), MAX(tg.group_time), MIN(t.time)), 5) AS unit_time,
                             COALESCE(MAX(tg.display_name), MIN(t.title)) AS unit_title,
                             COUNT(*) AS bookings,
                             SUM(COALESCE(t.participants, 0)) AS pax,
                             -- step 6.1: what language the departure is in (a mixed group
                             -- lists both of them, comma separated)
                             GROUP_CONCAT(DISTINCT NULLIF(TRIM(t.language), '') ORDER BY t.language SEPARATOR ', ') AS languages
                      FROM tours t
                      LEFT JOIN tour_groups tg ON t.group_id = tg.id
                      LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
                      $reportWhere
                      GROUP BY tour_unit
                      HAVING MAX(tg.guide_id) IS NULL AND MAX(t.guide_id) IS NULL
                      ORDER BY unit_date ASC, unit_time ASC, tour_unit ASC";
        $reportStmt = $conn->prepare($reportSql);
        if (count($reportParams) > 0) {
            $reportStmt->bind_param($reportTypes, ...$reportParams);
        }
        $reportStmt->execute();
        $reportResult = $reportStmt->get_result();
        $departures = [];
        while ($r = $reportResult->fetch_assoc()) {
            $departures[] = [
                'tour_unit' => $r['tour_unit'],
                'date' => $r['unit_date'],
                'time' => $r['unit_time'] ?: '00:00',
                'title' => $r['unit_title'],
                'bookings' => intval($r['bookings']),
                'pax' => intval($r['pax']),
                'language' => $r['languages'] !== null && $r['languages'] !== '' ? $r['languages'] : 'Unknown',
            ];
        }
        $reportStmt->close();
        return $departures;
    }
}

require_once __DIR__ . '/guide_product_fields.php'; // step 7.2b: products.duration_minutes

if (!function_exists('fwlDepartureUnits')) {
    /**
     * Every departure between two dates, the way the report counts them: one row per tour unit
     * (group, or single tour), cancelled bookings and ticket products left out, manual rows kept.
     * The effective guide is the group's guide, else a member tour's (COALESCE(tg.guide_id, t.guide_id)).
     *
     * @param mysqli   $conn
     * @param string   $start YYYY-MM-DD
     * @param string   $end   YYYY-MM-DD
     * @param int|null $guideId only this effective guide
     * @return array rows: departure_id, type, id, date, time, title, languages[], guests, bookings, guide_id, guide_name,
     *               duration_minutes (step 7.2b: the product's length, null = unknown)
     */
    function fwlDepartureUnits($conn, $start, $end, $guideId = null) {
        ensureProductDurationColumn($conn);
        $having = $guideId !== null ? "HAVING COALESCE(MAX(tg.guide_id), MAX(t.guide_id)) = ?" : "";
        $sql = "SELECT u.*, g.name AS guide_name FROM (
                    SELECT IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit,
                           COALESCE(MAX(tg.group_date), MIN(t.date)) AS unit_date,
                           LEFT(COALESCE(MAX(tg.departure_time), MAX(tg.group_time), MIN(t.time)), 5) AS unit_time,
                           COALESCE(MAX(tg.display_name), MIN(t.title)) AS unit_title,
                           COUNT(*) AS bookings,
                           SUM(COALESCE(t.participants, 0)) AS pax,
                           GROUP_CONCAT(DISTINCT NULLIF(TRIM(t.language), '') ORDER BY t.language SEPARATOR ', ') AS languages,
                           COALESCE(MAX(tg.guide_id), MAX(t.guide_id)) AS guide_id,
                           MAX(pr.duration_minutes) AS duration_minutes
                    FROM tours t
                    LEFT JOIN tour_groups tg ON t.group_id = tg.id
                    LEFT JOIN products pr ON t.product_id = pr.bokun_product_id
                    WHERE t.date >= ? AND t.date <= ? AND (pr.product_type = 'tour' OR t.product_id IS NULL) AND t.cancelled = 0
                    GROUP BY tour_unit
                    $having
                ) u
                LEFT JOIN guides g ON g.id = u.guide_id
                ORDER BY u.unit_date ASC, u.unit_time ASC, u.tour_unit ASC";
        $stmt = $conn->prepare($sql);
        if ($guideId !== null) {
            $gid = (int) $guideId;
            $stmt->bind_param('ssi', $start, $end, $gid);
        } else {
            $stmt->bind_param('ss', $start, $end);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $unit = $r['tour_unit'];
            $rows[] = [
                'departure_id' => $unit,
                'type' => $unit[0] === 'g' ? 'group' : 'single',
                'id' => (int) substr($unit, 1),
                'date' => $r['unit_date'],
                'time' => $r['unit_time'] ?: '00:00',
                'title' => $r['unit_title'],
                'languages' => $r['languages'] !== null && $r['languages'] !== '' ? explode(', ', $r['languages']) : [],
                'guests' => (int) $r['pax'],
                'bookings' => (int) $r['bookings'],
                'guide_id' => $r['guide_id'] !== null ? (int) $r['guide_id'] : null,
                'guide_name' => $r['guide_name'],
                'duration_minutes' => $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null,
            ];
        }
        $stmt->close();
        return $rows;
    }
}
