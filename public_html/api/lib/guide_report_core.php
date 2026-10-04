<?php
/**
 * Guide Tour Report classification (pure, no DB) — shared by guide-tour-report.php
 * and the CLI checks in tools/.
 *
 * Step 6.15: a departure whose bookings span more than one type gets an EFFECTIVE type,
 * its highest type, ranked Combo > Uffizi = Pitti = Accademia > Other (docs/DOMAIN_RULES.md,
 * "Guide Reports"). Two or more museums of the same rank with no Combo is not decided by the
 * owner yet: that unit stays "Mixed" so nothing is chosen silently.
 */

const GUIDE_REPORT_CATEGORY_ORDER = ['Combo', 'Uffizi', 'Pitti', 'Accademia', 'Other'];
const GUIDE_REPORT_CATEGORY_RANK  = ['Combo' => 3, 'Uffizi' => 2, 'Pitti' => 2, 'Accademia' => 2, 'Other' => 1];

/**
 * Classify a set of member titles into one unit category + composition.
 *
 * Returns [category, composition, composition_label, title_index]:
 *   - composition: ordered list of ['category' => ..., 'count' => ...] entries
 *     (fixed order Combo, Uffizi, Pitti, Accademia, Other; only present categories)
 *   - category: the single category when all members agree; else the effective (highest)
 *     type; else 'Mixed' when the highest rank is shared (e.g. Uffizi + Accademia, no Combo)
 *   - composition_label: '1 Combo + 1 Uffizi booking' for a mixed unit, '' otherwise
 *   - title_index: index in $titles of the first booking whose type equals the effective
 *     type (mixed units only), null otherwise
 */
function buildComposition($titles) {
    $counts = [];
    $cats = [];
    foreach ($titles as $i => $title) {
        $c = classifyTourCategory($title);
        $cats[$i] = $c;
        $counts[$c] = isset($counts[$c]) ? $counts[$c] + 1 : 1;
    }

    $composition = [];
    foreach (GUIDE_REPORT_CATEGORY_ORDER as $cat) {
        if (isset($counts[$cat])) {
            $composition[] = ['category' => $cat, 'count' => $counts[$cat]];
        }
    }

    if (count($composition) === 0) {
        return ['Other', [['category' => 'Other', 'count' => 0]], '', null];
    }
    if (count($composition) === 1) {
        return [$composition[0]['category'], $composition, '', null];
    }

    $parts = [];
    foreach ($composition as $entry) {
        $parts[] = $entry['count'] . ' ' . $entry['category'];
    }
    $last = $composition[count($composition) - 1]['count'];
    $label = implode(' + ', $parts) . ($last === 1 ? ' booking' : ' bookings');

    $topRank = 0;
    foreach ($composition as $entry) {
        $topRank = max($topRank, GUIDE_REPORT_CATEGORY_RANK[$entry['category']]);
    }
    $top = [];
    foreach ($composition as $entry) {
        if (GUIDE_REPORT_CATEGORY_RANK[$entry['category']] === $topRank) { $top[] = $entry['category']; }
    }
    if (count($top) !== 1) {
        return ['Mixed', $composition, $label, null];
    }

    $effective = $top[0];
    $titleIndex = null;
    foreach ($cats as $i => $c) {
        if ($c === $effective) { $titleIndex = $i; break; }
    }
    return [$effective, $composition, $label, $titleIndex];
}

/**
 * Classify a tour by its title (case-insensitive).
 *
 * Keyword rules (tweak here):
 *   uffizi    = title contains "uffizi"
 *   accademia = title contains "accademia" OR "david"
 *   pitti     = title contains "pitti" OR "boboli" OR "palatina" OR "palatine"
 *
 * Category:
 *   2+ of {uffizi, accademia, pitti} present -> "Combo"
 *   else uffizi    -> "Uffizi"
 *   else pitti     -> "Pitti"
 *   else accademia -> "Accademia"
 *   else           -> "Other"
 */
function classifyTourCategory($title) {
    $t = mb_strtolower($title ?? '');

    $uffizi    = (strpos($t, 'uffizi') !== false);
    $accademia = (strpos($t, 'accademia') !== false) || (strpos($t, 'david') !== false);
    $pitti     = (strpos($t, 'pitti') !== false)
                 || (strpos($t, 'boboli') !== false)
                 || (strpos($t, 'palatina') !== false)
                 || (strpos($t, 'palatine') !== false);

    $count = ($uffizi ? 1 : 0) + ($accademia ? 1 : 0) + ($pitti ? 1 : 0);

    if ($count >= 2) {
        return 'Combo';
    } elseif ($uffizi) {
        return 'Uffizi';
    } elseif ($pitti) {
        return 'Pitti';
    } elseif ($accademia) {
        return 'Accademia';
    }
    return 'Other';
}
