<?php
/**
 * pnl_links.php - step 6.2: two departures that run together under ONE guide, costed as one.
 *
 * The owner sometimes takes an Uffizi tour and an Uffizi+Accademia tour out at the same time with
 * a single guide. In the Daily P&L that guide must be paid once, not once per product and not
 * split in half. Everything else stays per person: tickets follow each product, radios follow
 * each person, revenue stays with the booking that produced it.
 *
 * This is a P&L-ONLY concept. Auto-grouping still refuses to mix products, and nothing here
 * touches Tours, tour_groups, payments, guide reminders or the evening digest.
 *
 * Functions only - no output, no routing, safe to require_once (same pattern as group_helpers.php).
 */

if (!function_exists('pnlLinkCombineRows')) {

    /**
     * Fold the member rows of each link into one merged row.
     *
     * @param array $rows      the per-unit rows pnlBuildRows() has already built
     * @param array $linksByUnit  tour_unit => ['link_key'=>string, 'link_date'=>string]
     * @param array $settings  the P&L rate settings
     * @param array $overrides tour_unit => pnl_tour_costs row (merged units are keyed 'm<id>')
     * @return array rows, with every linked set replaced by a single merged row
     */
    function pnlLinkCombineRows(array $rows, array $linksByUnit, array $settings, array $overrides = []) {
        if (!$linksByUnit) {
            return $rows;
        }

        $out = [];
        $buckets = [];   // link_key => list of member rows, in the order they appear
        foreach ($rows as $row) {
            $unit = $row['unit'];
            if (isset($linksByUnit[$unit])) {
                $buckets[$linksByUnit[$unit]['link_key']][] = $row;
            } else {
                $out[] = $row;
            }
        }

        foreach ($buckets as $linkKey => $members) {
            // A link with a single surviving member is not a merge - put it back untouched.
            if (count($members) < 2) {
                foreach ($members as $m) { $out[] = $m; }
                continue;
            }
            $out[] = pnlLinkMergeMembers($linkKey, $members, $settings,
                isset($overrides[$linkKey]) ? $overrides[$linkKey] : null);
        }

        usort($out, function ($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            if ($d !== 0) { return $d; }
            return strcmp($a['time'] ?? '', $b['time'] ?? '');
        });
        return $out;
    }

    /**
     * One merged row out of two or more member rows.
     *
     * Guide fee: ONE fee for the whole merged unit. It is the existing precedence applied to the
     * merged unit as a whole (outsourced > ticket product > private rate > highest category rate),
     * and never less than the most expensive member on its own - so if the members disagree, the
     * higher wins. `guide_rule` says which rule decided it.
     * Everything else adds up: tickets, radios, gelato, staff, other, revenue and PAX.
     */
    function pnlLinkMergeMembers($linkKey, array $members, array $settings, $linkOverride = null) {
        usort($members, function ($a, $b) { return strcmp($a['time'] ?? '', $b['time'] ?? ''); });
        $first = $members[0];

        $categories = [];
        $anyOutsourced = false;
        $anyPrivate = false;
        $allTicket = true;
        foreach ($members as $m) {
            $categories[$m['category']] = true;
            if (!empty($m['outsourced'])) { $anyOutsourced = true; }
            if (!empty($m['is_private'])) { $anyPrivate = true; }
            if (empty($m['is_ticket'])) { $allTicket = false; }
        }
        $category = count($categories) === 1 ? array_key_first($categories) : 'Mixed';

        // --- the one guide fee ------------------------------------------------------------
        $memberMax = 0.0;
        foreach ($members as $m) {
            $memberMax = max($memberMax, (float) $m['costs']['guide_cost']);
        }
        if ($anyOutsourced) {
            $combinedGuide = 0.0;
            $guideRule = 'outsourced (given to another agency) - no guide fee';
        } elseif ($allTicket) {
            $combinedGuide = 0.0;
            $guideRule = 'ticket products only - no guide fee';
        } elseif ($anyPrivate) {
            $combinedGuide = (float) pnlPrivateGuideRate($category, $settings);
            $guideRule = 'private rate for ' . $category;
        } else {
            $combinedGuide = 0.0;
            foreach (array_keys($categories) as $c) {
                $combinedGuide = max($combinedGuide, (float) pnlGuideRateForCategory($c, $settings));
            }
            $guideRule = count($categories) === 1
                ? 'category rate for ' . $category
                : 'highest category rate of ' . implode(' + ', array_keys($categories));
        }
        // Never pay less than one of the merged departures would have cost on its own.
        if (!$anyOutsourced && $memberMax > $combinedGuide) {
            $combinedGuide = $memberMax;
            $guideRule = 'highest single departure fee of the merged tours';
        }

        // --- everything else adds up -------------------------------------------------------
        $costs = ['ticket_cost' => 0.0, 'guide_cost' => round($combinedGuide, 2), 'radio_cost' => 0.0,
                  'gelato_cost' => 0.0, 'staff_cost' => 0.0, 'other_cost' => 0.0];
        $auto  = $costs;
        $pax = ['adults' => 0, 'children' => 0, 'infants' => 0, 'total' => 0];
        $retail = 0.0; $commission = 0.0; $net = 0.0;
        $bookings = 0; $cancelled = 0;
        $estimated = false; $revenueOverridden = false;
        $anyManual = false; $ticketUnknown = false; // step 6.4
        $channels = []; $guideNames = []; $titles = []; $overriddenFields = [];
        $breakdown = [];

        foreach ($members as $m) {
            foreach (['ticket_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost'] as $f) {
                $costs[$f] += (float) $m['costs'][$f];
                $auto[$f]  += (float) $m['costs']['auto'][$f];
            }
            $auto['guide_cost'] = max($auto['guide_cost'], (float) $m['costs']['auto']['guide_cost']);
            foreach (['adults', 'children', 'infants', 'total'] as $k) { $pax[$k] += (int) $m['pax'][$k]; }
            $retail     += (float) $m['revenue']['retail'];
            $commission += (float) $m['revenue']['commission'];
            $net        += (float) $m['revenue']['net'];
            $bookings   += (int) $m['bookings'];
            $cancelled  += (int) $m['cancelled'];
            if (!empty($m['revenue']['estimated']))  { $estimated = true; }
            if (!empty($m['revenue']['overridden'])) { $revenueOverridden = true; }
            // Step 6.4: one unknown ticket cost makes the merged ticket total unknown too.
            if (!empty($m['is_manual']))                { $anyManual = true; }
            if (!empty($m['costs']['ticket_unknown']))  { $ticketUnknown = true; }
            foreach (($m['channels'] ?? []) as $c) { if (!in_array($c, $channels, true)) { $channels[] = $c; } }
            if (!empty($m['guide_name']) && !in_array($m['guide_name'], $guideNames, true)) { $guideNames[] = $m['guide_name']; }
            $titles[] = $m['title'];
            foreach (($m['costs']['overridden'] ?? []) as $f) {
                if ($f !== 'guide_cost' && !in_array($f, $overriddenFields, true)) { $overriddenFields[] = $f; }
            }
            $breakdown[] = [
                'unit'        => $m['unit'],
                'title'       => $m['title'],
                'category'    => $m['category'],
                'time'        => $m['time'],
                'pax'         => $m['pax']['total'],
                'bookings'    => $m['bookings'],
                'net'         => round((float) $m['revenue']['net'], 2),
                'ticket_cost' => round((float) $m['costs']['ticket_cost'], 2),
                'guide_cost_alone' => round((float) $m['costs']['guide_cost'], 2),
                'notes'       => $m['notes'] ?? null,
            ];
        }

        // Outsourced: one handling fee for the merged unit, not one per member.
        if ($anyOutsourced) {
            $costs['other_cost'] = (float) $settings['outsource_fee'];
            $auto['other_cost']  = (float) $settings['outsource_fee'];
        }

        // --- an override on the MERGED row wins over anything the members carry --------------
        if ($linkOverride !== null) {
            foreach (['ticket_cost', 'guide_cost', 'radio_cost', 'gelato_cost', 'staff_cost', 'other_cost'] as $f) {
                if ($linkOverride[$f] !== null) {
                    $costs[$f] = (float) $linkOverride[$f];
                    if (!in_array($f, $overriddenFields, true)) { $overriddenFields[] = $f; }
                }
            }
            if ($linkOverride['revenue_override'] !== null) {
                $net = (float) $linkOverride['revenue_override'];
                $revenueOverridden = true;
            }
            if (intval($linkOverride['outsourced']) === 1) {
                $anyOutsourced = true;
                $costs['guide_cost'] = 0.0;
                $costs['other_cost'] = (float) $settings['outsource_fee'];
                $guideRule = 'outsourced (set on the merged row) - no guide fee';
            }
        }

        foreach ($costs as $f => $v) { $costs[$f] = round($v, 2); }
        foreach ($auto as $f => $v)  { $auto[$f]  = round($v, 2); }
        $totalCost = round(array_sum($costs), 2);
        $net = round($net, 2);

        return [
            'unit'        => $linkKey,
            'date'        => $first['date'],
            'time'        => $first['time'],
            'title'       => implode(' + ', array_map(function ($t) {
                                return mb_strlen($t) > 46 ? (mb_substr($t, 0, 44) . '…') : $t;
                             }, $titles)),
            'category'    => $category,
            'is_group'    => false,
            'is_manual'   => $anyManual,
            'is_ticket'   => $allTicket,
            'is_private'  => $anyPrivate,
            'guide_name'  => $guideNames ? implode(' / ', $guideNames) : null,
            'channels'    => $channels,
            'bookings'    => $bookings,
            'cancelled'   => $cancelled,
            'pax'         => $pax,
            'revenue'     => [
                'retail'     => round($retail, 2),
                'commission' => round($commission, 2),
                'net'        => $net,
                'estimated'  => $estimated,
                'overridden' => $revenueOverridden,
                'manual'     => $anyManual,
            ],
            'costs'       => array_merge($costs, [
                'total'      => $totalCost,
                'auto'       => $auto,
                'overridden' => $overriddenFields,
                'ticket_unknown' => $ticketUnknown && !in_array('ticket_cost', $overriddenFields, true),
                'guide_unknown'  => false, // a merged unit always gets one real fee (step 6.2)
            ]),
            'outsourced'  => $anyOutsourced,
            'profit'      => round($net - $totalCost, 2),
            'notes'       => $linkOverride !== null ? $linkOverride['notes'] : null,
            // Step 6.2: what makes this row a merged one.
            'merged'      => [
                'link_key'   => $linkKey,
                'units'      => array_column($breakdown, 'unit'),
                'guide_rule' => $guideRule,
                'members'    => $breakdown,
            ],
        ];
    }
}
