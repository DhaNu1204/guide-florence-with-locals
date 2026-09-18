<?php
/**
 * group_helpers.php - step 3.5: the two ways a group's guide reaches its member tours.
 * Shared by tour-groups.php (explicit assignment in the UI) and bokun_sync.php
 * (auto-grouping after a sync). Functions only - no output, no routing, safe to require_once.
 */

if (!function_exists('propagateGuideToTours')) {
    /**
     * Explicit assignment: the user set (or cleared) the guide of a group, so EVERY member
     * tour follows - this overwrites on purpose. (Moved unchanged from tour-groups.php.)
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
}

if (!function_exists('fillMissingGroupGuide')) {
    /**
     * Auto-grouping: a group was (re)built and inherited the guide of a member that already
     * had one. Members WITHOUT a guide - typically a booking that joined an already assigned
     * departure - get that guide too. A member that carries a DIFFERENT guide is never
     * overwritten here (nobody asked for that); it is only reported in the log.
     *
     * @return int number of tours that received the guide
     */
    function fillMissingGroupGuide($conn, $groupId, $guideId) {
        $groupId = (int) $groupId;
        $guideId = (int) $guideId;
        if ($groupId <= 0 || $guideId <= 0) {
            return 0;
        }

        $stmt = $conn->prepare("UPDATE tours SET guide_id = ?, needs_guide_assignment = 0, updated_at = NOW()
                                 WHERE group_id = ? AND guide_id IS NULL AND cancelled = 0");
        $stmt->bind_param('ii', $guideId, $groupId);
        $stmt->execute();
        $filled = $stmt->affected_rows;
        $stmt->close();

        $other = $conn->prepare("SELECT COUNT(*) AS n FROM tours WHERE group_id = ? AND guide_id IS NOT NULL AND guide_id <> ? AND cancelled = 0");
        $other->bind_param('ii', $groupId, $guideId);
        $other->execute();
        $differs = (int) ($other->get_result()->fetch_assoc()['n'] ?? 0);
        $other->close();
        if ($differs > 0) {
            error_log("Auto-group: group $groupId has guide $guideId but $differs member tour(s) carry a different guide - left untouched");
        }

        return $filled > 0 ? $filled : 0;
    }
}
