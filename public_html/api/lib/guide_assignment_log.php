<?php
/**
 * Step 6.19: who set or cleared a guide, when, and through which screen.
 *
 * One row per change: a single booking (tour_id) or a whole departure group (group_id).
 * Written by the paths a PERSON uses (Tours single edit, hand-entered form, group assign, a merge
 * that inherits a member's guide, the assistant's confirm / undo, auto-assign, a guide accepting
 * an availability request). The Bokun sync never writes or reads this table.
 *
 * Logging must never break an assignment: every failure is swallowed into error_log.
 */

if (!function_exists('ensureGuideAssignmentLogTable')) {
    function ensureGuideAssignmentLogTable($conn) {
        static $done = false;
        if ($done) return;
        $conn->query("
            CREATE TABLE IF NOT EXISTS guide_assignment_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tour_id INT NULL,
                group_id INT NULL,
                old_guide_id INT NULL,
                new_guide_id INT NULL,
                user_id INT NULL,
                username VARCHAR(100) NULL,
                source VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_gal_tour (tour_id),
                KEY idx_gal_group (group_id),
                KEY idx_gal_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    }
}

if (!function_exists('guideLogNorm')) {
    /** null / '' / 0 / '0' all mean "no guide". */
    function guideLogNorm($v) {
        if ($v === null || $v === '' || (int) $v <= 0) return null;
        return (int) $v;
    }
}

if (!function_exists('guideLogActor')) {
    /**
     * The signed-in user of this request, set once by the endpoint after Middleware auth so helper
     * functions deep in an endpoint can log without passing the user around.
     * guideLogActor($user) sets it; guideLogActor() reads it (null = not set, e.g. the public guide link).
     */
    function guideLogActor($user = false) {
        static $actor = null;
        if ($user !== false) { $actor = is_array($user) ? $user : null; }
        return $actor;
    }
}

if (!function_exists('logGuideChange')) {
    /**
     * @param mysqli     $conn
     * @param int|null   $tourId   a single booking, or null
     * @param int|null   $groupId  a departure group, or null
     * @param mixed      $oldGuide previous guide id (null = none)
     * @param mixed      $newGuide new guide id (null = cleared)
     * @param string     $source   tours_page | manual_form | create | group_assign | group_merge |
     *                             assistant | assistant_undo | auto_assign | guide_request
     * @param array|null $user     the Middleware user (id, username); null = guideLogActor()
     *                             (also null for the public guide link)
     * @return bool true when a row was written (nothing is written when the guide did not change)
     */
    function logGuideChange($conn, $tourId, $groupId, $oldGuide, $newGuide, $source, $user = null) {
        $old = guideLogNorm($oldGuide);
        $new = guideLogNorm($newGuide);
        if ($old === $new) return false;
        if ($user === null) { $user = guideLogActor(); }
        try {
            ensureGuideAssignmentLogTable($conn);
            $tid = $tourId !== null ? (int) $tourId : null;
            $gid = $groupId !== null ? (int) $groupId : null;
            $uid = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
            $uname = is_array($user) && isset($user['username']) ? substr((string) $user['username'], 0, 100) : null;
            $src = substr((string) $source, 0, 32);
            $s = $conn->prepare("INSERT INTO guide_assignment_log (tour_id, group_id, old_guide_id, new_guide_id, user_id, username, source)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
            $s->bind_param('iiiiiss', $tid, $gid, $old, $new, $uid, $uname, $src);
            $ok = $s->execute();
            $s->close();
            return (bool) $ok;
        } catch (Throwable $e) {
            error_log('Step 6.19: guide_assignment_log write failed: ' . $e->getMessage());
            return false;
        }
    }
}
