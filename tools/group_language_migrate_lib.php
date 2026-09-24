<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only (step 6.12): never deployed
/**
 * Step 6.12 - the migration core, a function so tools/grouping_scenarios.php can run it on its
 * own synthetic date only. See tools/group_language_migrate.php for what it does.
 *
 * @param string|null $onlyDate  limit to one group_date (tests); null = every date >= GROUP_LANGUAGE_KEY_FROM
 * @return array [stats, report lines] or null when the auto_group lock is busy
 */
if (!function_exists('groupLanguageTitleOf')) {
    function groupLanguageTitleOf(array $members) {
        $n = [];
        foreach ($members as $m) { $n[$m['title']] = ($n[$m['title']] ?? 0) + 1; }
        arsort($n);
        return (string) array_key_first($n);
    }
}

function groupLanguageMigrate($conn, $apply, $onlyDate = null) {
    $from = GROUP_LANGUAGE_KEY_FROM;
    $lock = $conn->query("SELECT GET_LOCK('auto_group', 30) AS locked")->fetch_assoc();
    if (!$lock || !$lock['locked']) { return null; }

    $stmt = $conn->prepare("
        SELECT tg.id, tg.group_date, tg.group_time, tg.bucket_key, tg.guide_id, tg.max_pax, tg.total_pax,
               g.languages AS guide_languages, g.name AS guide_name,
               t.id AS tour_id, t.title, t.language, t.participants, t.product_id, t.cancelled, t.guide_id AS tour_guide_id,
               (SELECT COUNT(*) FROM payments p WHERE p.tour_id = t.id) AS payments
          FROM tour_groups tg
          JOIN tours t ON t.group_id = tg.id
          LEFT JOIN guides g ON g.id = tg.guide_id
         WHERE tg.is_manual_merge = 0 AND tg.group_date >= ? AND (? = '' OR tg.group_date = ?)
         ORDER BY tg.group_date, tg.group_time, tg.id, t.id");
    $only = (string) $onlyDate;
    $stmt->bind_param('sss', $from, $only, $only);
    $stmt->execute();
    $res = $stmt->get_result();
    $groups = [];
    while ($r = $res->fetch_assoc()) {
        $groups[(int) $r['id']]['row'] = $r;
        $groups[(int) $r['id']]['members'][] = $r;
    }
    $stmt->close();

    $stats = ['groups' => count($groups), 'rewritten' => 0, 'already' => 0, 'no_language' => 0, 'split' => 0, 'held' => 0,
              'new_groups' => 0, 'tours_moved' => 0, 'tours_detached' => 0, 'guides_cleared' => 0, 'pnl_rows' => 0];
    $report = [];

    $pnlKeys = function ($gid, $key) use ($conn, $apply, &$stats) {
        $unit = 'g' . $gid;
        foreach (['pnl_tour_costs', 'pnl_unit_links'] as $table) {
            $chk = $conn->query("SHOW TABLES LIKE '$table'");
            if (!$chk || $chk->num_rows === 0) { continue; }
            $q = $conn->prepare("SELECT COUNT(*) n FROM $table WHERE tour_unit = ? AND (bucket_key IS NULL OR bucket_key <> ?)");
            $q->bind_param('ss', $unit, $key);
            $q->execute();
            $n = (int) $q->get_result()->fetch_assoc()['n'];
            $q->close();
            if ($n > 0 && $apply) {
                $u = $conn->prepare("UPDATE $table SET bucket_key = ? WHERE tour_unit = ?");
                $u->bind_param('ss', $key, $unit);
                $u->execute();
                $u->close();
            }
            $stats['pnl_rows'] += $n;
        }
    };

    foreach ($groups as $gid => $g) {
        $row = $g['row'];
        $active = array_values(array_filter($g['members'], function ($m) { return (int) $m['cancelled'] === 0; }));
        $langs = groupMemberLanguages($g['members']);
        $productId = $active ? (int) $active[0]['product_id'] : (int) $row['product_id'];
        $base = groupBucketKey($productId, $row['group_date'], $row['group_time']);
        $label = sprintf("g%d %s %s %d", $gid, $row['group_date'], substr($row['group_time'], 0, 5), $productId);

        if (count($langs) === 0) {
            $stats['no_language']++;
            $report[] = "NO LANGUAGE (left as is)\t$label";
            continue;
        }

        if (count($langs) === 1) {
            $key = groupBucketKey($productId, $row['group_date'], $row['group_time'], $langs[0]);
            if ((string) $row['bucket_key'] === $key) { $stats['already']++; continue; }
            if ($apply) {
                $conn->begin_transaction();
                $u = $conn->prepare("UPDATE tour_groups SET bucket_key = ? WHERE id = ?");
                $u->bind_param('si', $key, $gid);
                $u->execute();
                $u->close();
                $pnlKeys($gid, $key);
                $conn->commit();
            } else {
                $pnlKeys($gid, $key);
            }
            $stats['rewritten']++;
            continue;
        }

        // ---- mixed ----
        $byLang = [];
        $payments = 0;
        foreach ($active as $m) {
            $payments += (int) $m['payments'];
            $l = tourLanguageKey($m['language']);
            if ($l !== null) { $byLang[$l][] = $m; }
        }
        foreach ($g['members'] as $m) { if ((int) $m['cancelled'] === 1) { $payments += (int) $m['payments']; } }
        $paxOf = function ($ms) { return array_sum(array_map(function ($m) { return (int) $m['participants']; }, $ms)); };
        $desc = [];
        foreach ($byLang as $l => $ms) { $desc[] = "$l " . $paxOf($ms) . ' pax/' . count($ms) . ' bk'; }
        $label .= ' [' . implode(', ', $desc) . ']' . ($row['guide_id'] ? ' guide=' . $row['guide_name'] . ' (' . $row['guide_languages'] . ')' : ' no guide');

        $hold = null;
        $keeper = null;
        if ($payments > 0) {
            $hold = "payment recorded ($payments)";
        } elseif ($row['guide_id']) {
            $speaks = [];
            foreach (preg_split('/[,;\/]+/', (string) $row['guide_languages']) as $gl) {
                $c = tourLanguageCanonical($gl);
                if ($c !== null && isset($byLang[$c])) { $speaks[$c] = true; }
            }
            if (count($speaks) === 1) {
                $keeper = array_key_first($speaks);
            } else {
                $hold = count($speaks) === 0 ? 'guide speaks none of these languages (or languages not set)' : 'guide speaks more than one of these languages';
            }
        } else {
            $order = array_keys($byLang);
            usort($order, function ($a, $b) use ($byLang, $paxOf) {
                $d = $paxOf($byLang[$b]) - $paxOf($byLang[$a]);
                if ($d !== 0) { return $d; }
                $d = count($byLang[$b]) - count($byLang[$a]);
                return $d !== 0 ? $d : strcmp($a, $b);
            });
            // The larger PAX keeps the id - unless it is a single booking (not a group); then the
            // next language that still forms a group keeps it.
            foreach ($order as $l) { if (count($byLang[$l]) >= 2) { $keeper = $l; break; } }
            if ($keeper === null) { $hold = 'no language has 2 bookings (would dissolve the group)'; }
        }
        if ($hold === null && count($byLang[$keeper]) < 2) {
            $hold = "the guide's language ($keeper) is a single booking - splitting would dissolve the group";
        }
        if ($hold !== null) {
            $stats['held']++;
            $report[] = "HELD: $hold\t$label";
            continue;
        }

        $stats['split']++;
        $lines = ["SPLIT\t$label -> g$gid keeps $keeper"];
        if ($apply) { $conn->begin_transaction(); }
        try {
            $groupGuide = $row['guide_id'] ? (int) $row['guide_id'] : null;
            $moveOut = []; // tours leaving g$gid
            foreach ($active as $m) {
                $l = tourLanguageKey($m['language']);
                if ($l !== $keeper) { $moveOut[] = $m; } // other languages and (2+ languages) no-language members
            }
            foreach ($byLang as $l => $ms) {
                if ($l === $keeper) { continue; }
                $ids = array_map(function ($m) { return (int) $m['tour_id']; }, $ms);
                if (count($ms) >= 2) {
                    $key = groupBucketKey($productId, $row['group_date'], $row['group_time'], $l);
                    $newId = 0;
                    if ($apply) {
                        $title = groupLanguageTitleOf($ms);
                        $pax = $paxOf($ms);
                        $maxPax = (int) $row['max_pax'];
                        $ins = $conn->prepare("INSERT INTO tour_groups (group_date, group_time, display_name, total_pax, is_manual_merge, max_pax, bucket_key)
                                               VALUES (?, ?, ?, ?, 0, ?, ?)");
                        $ins->bind_param('sssiis', $row['group_date'], $row['group_time'], $title, $pax, $maxPax, $key);
                        $ins->execute();
                        $newId = (int) $conn->insert_id;
                        $ins->close();
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $mv = $conn->prepare("UPDATE tours SET group_id = ? WHERE id IN ($ph)");
                        $mv->bind_param(str_repeat('i', count($ids) + 1), $newId, ...$ids);
                        $mv->execute();
                        $mv->close();
                    }
                    $stats['new_groups']++;
                    $stats['tours_moved'] += count($ids);
                    $lines[] = "   $l -> new group" . ($apply ? " g$newId" : '') . ' (tours ' . implode(',', $ids) . ')';
                } else {
                    $lines[] = "   $l -> loose booking (tour {$ids[0]})";
                }
            }
            // Loose: single-booking languages and no-language members.
            $loose = [];
            foreach ($moveOut as $m) {
                $l = tourLanguageKey($m['language']);
                if ($l === null || count($byLang[$l]) < 2) { $loose[] = (int) $m['tour_id']; }
            }
            if ($loose) {
                if ($apply) {
                    $ph = implode(',', array_fill(0, count($loose), '?'));
                    $d = $conn->prepare("UPDATE tours SET group_id = NULL WHERE id IN ($ph)");
                    $d->bind_param(str_repeat('i', count($loose)), ...$loose);
                    $d->execute();
                    $d->close();
                }
                $stats['tours_detached'] += count($loose);
            }
            // A member that left carried the group's guide only because it was propagated to it.
            if ($groupGuide) {
                foreach ($moveOut as $m) {
                    if ((int) $m['tour_guide_id'] !== $groupGuide) { continue; }
                    if ($apply) {
                        $c = $conn->prepare("UPDATE tours SET guide_id = NULL, needs_guide_assignment = 1, updated_at = NOW() WHERE id = ?");
                        $tid = (int) $m['tour_id'];
                        $c->bind_param('i', $tid);
                        $c->execute();
                        $c->close();
                    }
                    $stats['guides_cleared']++;
                }
            }
            $keyKeep = groupBucketKey($productId, $row['group_date'], $row['group_time'], $keeper);
            if ($apply) {
                $pax = $paxOf($byLang[$keeper]);
                $u = $conn->prepare("UPDATE tour_groups SET bucket_key = ?, total_pax = ?, updated_at = NOW() WHERE id = ?");
                $u->bind_param('sii', $keyKeep, $pax, $gid);
                $u->execute();
                $u->close();
            }
            $pnlKeys($gid, $keyKeep);
            if ($apply) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($apply) { $conn->rollback(); }
            $lines[] = '   FAILED, rolled back: ' . $e->getMessage();
        }
        $report = array_merge($report, $lines);
    }

    $conn->query("SELECT RELEASE_LOCK('auto_group')");
    return [$stats, $report];
}
