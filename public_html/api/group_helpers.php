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
    function groupBucketKey($productId, $date, $time, $language = null) {
        $key = intval($productId) . '|' . substr((string) $date, 0, 10) . '|' . normalizeGroupTime($time);
        // Step 6.12: a guide speaks one language, so from GROUP_LANGUAGE_KEY_FROM on the language
        // is part of what a departure is: "961801|2026-09-25|14:30|English".
        return $language !== null ? $key . '|' . $language : $key;
    }
}

if (!defined('GROUP_LANGUAGE_KEY_FROM')) {
    /**
     * Step 6.12: auto-groups on this date and later are one language each. Earlier dates keep
     * the old product|date|time key and behave exactly as before - those tours already ran, and
     * re-splitting them would change guide costs, payments and closed P&L months after the fact.
     * A fixed date, not "today": a split made today must not merge back once its day is past.
     */
    define('GROUP_LANGUAGE_KEY_FROM', '2026-09-24');
}

if (!function_exists('groupLanguageKeyApplies')) {
    function groupLanguageKeyApplies($date) {
        return substr((string) $date, 0, 10) >= GROUP_LANGUAGE_KEY_FROM;
    }
}

if (!function_exists('tourLanguageKey')) {
    /** Step 6.12: the language a booking is grouped by, or null when it has none ("Unknown"). */
    function tourLanguageKey($language) {
        $l = trim((string) $language);
        return ($l === '' || strcasecmp($l, 'Unknown') === 0) ? null : $l;
    }
}

if (!function_exists('groupMemberLanguages')) {
    /**
     * Step 6.12: the distinct known languages of a group's ACTIVE members, sorted. Two or more
     * = a mixed-language group. Members without a language do not count as a language.
     */
    function groupMemberLanguages(array $members) {
        $langs = [];
        foreach ($members as $m) {
            if (!empty($m['cancelled'])) { continue; }
            $l = tourLanguageKey($m['language'] ?? null);
            if ($l !== null) { $langs[$l] = true; }
        }
        $langs = array_keys($langs);
        sort($langs);
        return $langs;
    }
}

if (!function_exists('buildGroupBuckets')) {
    /**
     * Step 3.7: tours -> [bucketKey => [tours]], insertion order preserved (the caller sorts by
     * product_id, date, time, id, and that order decides how a bucket is split below).
     *
     * Step 6.12: from GROUP_LANGUAGE_KEY_FROM on, one departure (product, date, time) gives one
     * bucket PER LANGUAGE - English and Italian at 14:30 are two groups, each with its own guide.
     * A booking with no language joins only when exactly one language is present at that
     * departure; otherwise (none, or two or more) it stays on its own, so the owner places it by
     * hand. Nothing guesses a language.
     */
    function buildGroupBuckets(array $tours) {
        $departures = [];
        foreach ($tours as $tour) {
            $departures[groupBucketKey($tour['product_id'], $tour['date'], $tour['time'])][] = $tour;
        }

        $buckets = [];
        foreach ($departures as $key => $depTours) {
            $buckets += splitDepartureByLanguage($key, $depTours);
        }
        return $buckets;
    }
}

if (!function_exists('splitDepartureByLanguage')) {
    /**
     * Step 6.12: one departure's bookings -> [key|language => tours]. Before
     * GROUP_LANGUAGE_KEY_FROM the departure stays one bucket, as it always was. Shared by the
     * sync and the Tours page "Auto-Group" button so the two can never disagree.
     */
    function splitDepartureByLanguage($key, array $depTours) {
        if (!$depTours || !groupLanguageKeyApplies($depTours[0]['date'])) {
            return [$key => $depTours];
        }
        $known = [];
        foreach ($depTours as $tour) {
            $l = tourLanguageKey($tour['language'] ?? null);
            if ($l !== null) { $known[$l] = true; }
        }
        $only = count($known) === 1 ? array_key_first($known) : null;
        $buckets = [];
        foreach ($depTours as $tour) {
            $l = tourLanguageKey($tour['language'] ?? null) ?? $only;
            if ($l === null) {
                $buckets[$key . '|#' . intval($tour['id'])][] = $tour; // alone: never forms a group
                continue;
            }
            $buckets[$key . '|' . $l][] = $tour;
        }
        return $buckets;
    }
}

if (!function_exists('mixedLanguageAutoGroupIds')) {
    /**
     * Step 6.12: auto groups on/after GROUP_LANGUAGE_KEY_FROM whose active members speak two or
     * more languages. The sync leaves these exactly as they are (like a manual merge): only the
     * one-off migration splits them, because only it knows which half keeps the guide and it
     * refuses when a payment is recorded. Anything it left is the owner's to decide.
     *
     * @param array $rows  [group_id, date, language, cancelled] per member of an auto group
     * @return array       groupId => true
     */
    function mixedLanguageAutoGroupIds(array $rows) {
        $byGroup = [];
        foreach ($rows as $r) {
            if (!groupLanguageKeyApplies($r['date'])) { continue; }
            $byGroup[(int) $r['group_id']][] = $r;
        }
        $mixed = [];
        foreach ($byGroup as $gid => $members) {
            if (count(groupMemberLanguages($members)) > 1) { $mixed[$gid] = true; }
        }
        return $mixed;
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

// ---------------------------------------------------------------------------------------------
// Step 6.13: a note on a group (tour_groups.notes) - one note per departure, internal only (it is
// never sent to a guide). The sync never writes it. When a group goes away or loses a booking the
// note is never silently lost: it is appended to the member bookings' own notes, marked
// "[Group note] ...", and never twice.
// ---------------------------------------------------------------------------------------------

if (!defined('GROUP_NOTE_MARK')) {
    define('GROUP_NOTE_MARK', '[Group note]');
}
if (!defined('GROUP_NOTE_MAX_LENGTH')) {
    define('GROUP_NOTE_MAX_LENGTH', 2000);
}

if (!function_exists('groupNoteClean')) {
    /** A note as stored: trimmed, "\n" line ends; null when there is nothing left. */
    function groupNoteClean($note) {
        if ($note === null || !is_scalar($note)) { return null; }
        $n = trim(str_replace(["\r\n", "\r"], "\n", (string) $note));
        return $n === '' ? null : $n;
    }
}

if (!function_exists('appendGroupNoteText')) {
    /**
     * A booking's own note with the group note appended ("[Group note] ..." on a new line).
     * Never overwrites; returns the existing text unchanged when that exact marked note is
     * already in it, or when there is no group note.
     */
    function appendGroupNoteText($existing, $groupNote) {
        $clean = groupNoteClean($groupNote);
        if ($clean === null) { return $existing; }
        $marked = GROUP_NOTE_MARK . ' ' . $clean;
        $ex = (string) $existing;
        if (strpos($ex, $marked) !== false) { return $existing; }
        return trim($ex) === '' ? $marked : rtrim($ex) . "\n" . $marked;
    }
}

if (!function_exists('joinGroupNotes')) {
    /** Several notes (merging two noted groups) -> one, each on its own line, none dropped, no repeats. */
    function joinGroupNotes(array $notes) {
        $out = [];
        foreach ($notes as $n) {
            $c = groupNoteClean($n);
            if ($c !== null && !in_array($c, $out, true)) { $out[] = $c; }
        }
        return $out ? implode("\n", $out) : null;
    }
}

if (!function_exists('ensureGroupNotesColumn')) {
    /** Self-provision (also database/migrations/20260924_tour_groups_notes.sql). */
    function ensureGroupNotesColumn($conn) {
        $c = $conn->query("SHOW COLUMNS FROM tour_groups LIKE 'notes'");
        if ($c && $c->num_rows === 0) {
            $conn->query("ALTER TABLE tour_groups ADD COLUMN `notes` TEXT NULL DEFAULT NULL AFTER `guide_name`");
        }
        return true;
    }
}

if (!function_exists('groupNoteOf')) {
    function groupNoteOf($conn, $groupId) {
        $stmt = $conn->prepare("SELECT notes FROM tour_groups WHERE id = ?");
        $gid = (int) $groupId;
        $stmt->bind_param('i', $gid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? groupNoteClean($row['notes']) : null;
    }
}

if (!function_exists('copyGroupNoteToTours')) {
    /**
     * Append a group note to each booking's own note. The "remaining" bookings are the live ones;
     * only when every one of them is cancelled does the note go onto the cancelled ones, so it is
     * still somewhere. Returns the number of bookings whose note changed.
     */
    function copyGroupNoteToTours($conn, $groupNote, array $tourIds) {
        if (groupNoteClean($groupNote) === null) { return 0; }
        $tourIds = array_values(array_unique(array_map('intval', $tourIds)));
        if (!$tourIds) { return 0; }
        $ph = implode(',', array_fill(0, count($tourIds), '?'));
        $stmt = $conn->prepare("SELECT id, notes, cancelled FROM tours WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($tourIds)), ...$tourIds);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        $live = array_values(array_filter($rows, function ($r) { return (int) $r['cancelled'] === 0; }));
        $changed = 0;
        foreach (($live ?: $rows) as $r) {
            $new = appendGroupNoteText($r['notes'], $groupNote);
            if ($new === $r['notes']) { continue; }
            $u = $conn->prepare("UPDATE tours SET notes = ? WHERE id = ?");
            $tid = (int) $r['id'];
            $u->bind_param('si', $new, $tid);
            $u->execute();
            $u->close();
            $changed++;
        }
        return $changed;
    }
}

if (!function_exists('preserveNotesOfGroupsAboutToBeDeleted')) {
    /**
     * Auto-grouping deletes every group row that has no members left. Before it does, a group
     * that carries a note hands the note to the bookings that were in it this run.
     *
     * @param array $formerMembership tourId => groupId as it was before this run
     * @return int bookings whose note changed
     */
    function preserveNotesOfGroupsAboutToBeDeleted($conn, array $formerMembership) {
        $res = $conn->query("SELECT id, notes FROM tour_groups
                              WHERE notes IS NOT NULL AND TRIM(notes) <> ''
                                AND id NOT IN (SELECT DISTINCT group_id FROM tours WHERE group_id IS NOT NULL)");
        if (!$res) { return 0; }
        $changed = 0;
        while ($g = $res->fetch_assoc()) {
            $gid = (int) $g['id'];
            $members = [];
            foreach ($formerMembership as $tid => $old) {
                if ((int) $old === $gid) { $members[] = (int) $tid; }
            }
            if (!$members) {
                error_log("Group note: group $gid is deleted with a note and no known former bookings");
                continue;
            }
            $changed += copyGroupNoteToTours($conn, $g['notes'], $members);
        }
        return $changed;
    }
}
