<?php
/**
 * group_helpers.php - step 3.5: the two ways a group's guide reaches its member tours.
 * Shared by tour-groups.php (explicit assignment in the UI) and bokun_sync.php
 * (auto-grouping after a sync). Functions only - no output, no routing, safe to require_once.
 */

if (!function_exists('normalizeGroupTime')) {
    /**
     * Step 3.7: 'HH:MM' from anything the DB or Bokun hands us ('09:00:00', '9:00', '09:00').
     * The bucket key must not change just because the string form did.
     */
    function normalizeGroupTime($time) {
        $parts = explode(':', (string) $time);
        return sprintf('%02d:%02d', intval($parts[0] ?? 0), intval($parts[1] ?? 0));
    }
}

if (!function_exists('groupBucketKey')) {
    /**
     * Step 3.7: the NATURAL identity of a departure - product, date, start time. This is what a
     * group really is; its `id` is only a surrogate that used to be thrown away every 15 minutes.
     * NOT unique among groups: one bucket legitimately produces several groups when the PAX cap
     * for the product splits it (measured on production: two 14:30 groups of product 961801 on
     * 2026-08-12). It is a lookup key, not a constraint.
     */
    function groupBucketKey($productId, $date, $time) {
        return intval($productId) . '|' . substr((string) $date, 0, 10) . '|' . normalizeGroupTime($time);
    }
}

if (!function_exists('buildGroupBuckets')) {
    /**
     * Step 3.7: tours -> [bucketKey => [tours]], insertion order preserved (the caller sorts by
     * product_id, date, time, id, and that order decides how a bucket is split below).
     */
    function buildGroupBuckets(array $tours) {
        $buckets = [];
        foreach ($tours as $tour) {
            $key = groupBucketKey($tour['product_id'], $tour['date'], $tour['time']);
            $buckets[$key][] = $tour;
        }
        return $buckets;
    }
}

if (!function_exists('splitBucketByPax')) {
    /**
     * Step 3.7: the per-product PAX cap splits one departure into several groups. Unchanged
     * behaviour, extracted from bokun_sync.php so it can be tested without a database.
     * Sub-groups of a single booking are dropped by the caller (a group needs 2+ bookings).
     */
    function splitBucketByPax(array $bucketTours, $maxPax) {
        $subGroups = [];
        $current = [];
        $currentPax = 0;
        foreach ($bucketTours as $tour) {
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
}

if (!function_exists('matchDesiredToExistingGroups')) {
    /**
     * Step 3.7 - the heart of stable identity. Decide which EXISTING group row each desired
     * group should keep, by how many members they share. A group row is claimed at most once;
     * ties go to the lower group id so two runs over unchanged data always decide the same way.
     *
     * @param array $desired      list of lists of tour ids (the member set we want)
     * @param array $tourToGroup  tourId => groupId as the database has it right now
     * @return array              index in $desired => groupId to reuse, or null = create a new one
     */
    function matchDesiredToExistingGroups(array $desired, array $tourToGroup) {
        $candidates = [];
        foreach ($desired as $i => $tourIds) {
            $overlap = [];
            foreach ($tourIds as $tid) {
                if (isset($tourToGroup[$tid])) {
                    $gid = (int) $tourToGroup[$tid];
                    $overlap[$gid] = ($overlap[$gid] ?? 0) + 1;
                }
            }
            foreach ($overlap as $gid => $n) {
                $candidates[] = ['i' => $i, 'gid' => $gid, 'n' => $n];
            }
        }

        // Best overlap first; then the earlier desired group; then the lower group id.
        usort($candidates, function ($a, $b) {
            if ($a['n'] !== $b['n']) { return $b['n'] - $a['n']; }
            if ($a['i'] !== $b['i']) { return $a['i'] - $b['i']; }
            return $a['gid'] - $b['gid'];
        });

        $result = array_fill(0, count($desired), null);
        $claimed = [];
        foreach ($candidates as $c) {
            if ($result[$c['i']] !== null || isset($claimed[$c['gid']])) { continue; }
            $result[$c['i']] = $c['gid'];
            $claimed[$c['gid']] = true;
        }
        return $result;
    }
}

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
