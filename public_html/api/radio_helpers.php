<?php
/**
 * radio_helpers.php - step 6.3: the pure parts of the radio order the owner sends Vox Firenze
 * every afternoon. Functions only - no output, no routing, safe to require_once.
 *
 * What he writes today, copied from the WhatsApp group:
 *
 *     Ciao,
 *
 *     Uffizi
 *     9:00 - 3
 *     9:30 - 9
 *     9:30 - 9
 *     10:00 - 4
 *
 *     Grazie
 *
 * One line per DEPARTURE (a group of bookings counts once, with the group's PAX), two departures
 * at the same time are two lines, times carry no leading zero, and the number is guests only -
 * the supplier adds the guide's transmitter itself.
 */

// The heading for a tour that names no museum at all. One word, easy to change if the owner
// prefers "Firenze" or "Centro".
if (!defined('RADIO_OTHER_SECTION')) { define('RADIO_OTHER_SECTION', 'Altro'); }

if (!function_exists('radioMuseumForTitle')) {
    /**
     * Which museum heading a tour belongs under. The FIRST museum named in the title wins,
     * because that is where the group meets: "Uffizi & Accademia Walking Tour" starts at the
     * Uffizi. Returns the heading, whether the match was unambiguous, and why.
     */
    function radioMuseumForTitle($title) {
        $t = mb_strtolower((string) $title);
        // Ordered: the earliest match in the TITLE decides, not the order of this list.
        // Owner's answer 2026-09-21: a tour that names a museum gets that museum's heading -
        // Bargello and Palazzo Vecchio included. A tour that names none (Medici, Michelangelo,
        // the Renaissance walking tours) goes in one catch-all section at the end.
        // 'Vasari' is deliberately NOT a heading of its own: the Vasari Corridor is inside the
        // Uffizi, and "Uffizi ... with Optional Vasari Corridor" already matches Uffizi first.
        $keywords = [
            'Uffizi'          => ['uffizi'],
            'Accademia'       => ['accademia', 'david'],
            'Pitti'           => ['pitti', 'boboli', 'palatina', 'palatine'],
            'Borghese'        => ['borghese'],
            'Duomo'           => ['duomo', 'cathedral', 'cupola', 'brunelleschi'],
            'Bargello'        => ['bargello'],
            'Palazzo Vecchio' => ['palazzo vecchio'],
        ];

        $hits = [];
        foreach ($keywords as $museum => $words) {
            foreach ($words as $w) {
                $pos = mb_strpos($t, $w);
                if ($pos !== false) {
                    if (!isset($hits[$museum]) || $pos < $hits[$museum]) { $hits[$museum] = $pos; }
                }
            }
        }

        if (!$hits) {
            return ['museum' => RADIO_OTHER_SECTION, 'confident' => false, 'why' => 'no museum named in the title'];
        }
        asort($hits); // earliest position first
        $museum = array_key_first($hits);
        if (count($hits) === 1) {
            return ['museum' => $museum, 'confident' => true, 'why' => 'only museum in the title'];
        }
        return [
            'museum'    => $museum,
            'confident' => false,
            'why'       => 'several museums (' . implode(' + ', array_keys($hits)) . ') - took the first one, where the group meets',
        ];
    }
}

if (!function_exists('radioSectionOrder')) {
    /**
     * The order the sections appear in his messages: Uffizi, then Accademia, then the rest
     * alphabetically so the output is stable.
     */
    function radioSectionOrder($museum) {
        // Uffizi first, then Accademia, then the other museums alphabetically, and the catch-all
        // section always last.
        $fixed = ['Uffizi' => 0, 'Accademia' => 1, RADIO_OTHER_SECTION => 3];
        return isset($fixed[$museum]) ? $fixed[$museum] : 2;
    }
}

if (!function_exists('radioFormatTime')) {
    /** '09:30:00' -> '9:30' (no leading zero, exactly as he writes it). */
    function radioFormatTime($time) {
        $parts = explode(':', (string) $time);
        return intval($parts[0]) . ':' . sprintf('%02d', intval($parts[1] ?? 0));
    }
}

if (!function_exists('radioGreeting')) {
    /** "Ciao," before 15:00 Europe/Rome, "Buonasera," from 15:00. */
    function radioGreeting(DateTimeInterface $romeNow) {
        return ((int) $romeNow->format('G')) < 15 ? 'Ciao,' : 'Buonasera,';
    }
}

if (!function_exists('radioBuildSections')) {
    /**
     * Departures -> the sections of the message.
     *
     * @param array $departures each: ['museum'=>string,'time'=>'HH:MM:SS','pax'=>int, ...]
     * @return array [['museum'=>..., 'lines'=>[['time'=>'9:30','pax'=>9, ...]]]]
     */
    function radioBuildSections(array $departures) {
        $byMuseum = [];
        foreach ($departures as $d) {
            $byMuseum[$d['museum']][] = $d;
        }
        uksort($byMuseum, function ($a, $b) {
            $oa = radioSectionOrder($a);
            $ob = radioSectionOrder($b);
            if ($oa !== $ob) { return $oa - $ob; }
            return strcmp($a, $b);
        });

        $sections = [];
        foreach ($byMuseum as $museum => $items) {
            usort($items, function ($x, $y) {
                $c = strcmp($x['time'], $y['time']);
                if ($c !== 0) { return $c; }
                return strcmp((string) ($x['unit'] ?? ''), (string) ($y['unit'] ?? ''));
            });
            $lines = [];
            foreach ($items as $i) {
                $lines[] = array_merge($i, ['label' => radioFormatTime($i['time']) . ' - ' . (int) $i['pax']]);
            }
            $sections[] = ['museum' => $museum, 'lines' => $lines];
        }
        return $sections;
    }
}

if (!function_exists('radioRenderMessage')) {
    /**
     * The message exactly as he sends it: greeting, blank line, then each museum heading with its
     * lines, a blank line between sections, and "Grazie" at the end.
     */
    function radioRenderMessage(array $sections, $greeting = 'Ciao,', $closing = 'Grazie') {
        $out = [$greeting, ''];
        foreach ($sections as $s) {
            $out[] = $s['museum'];
            foreach ($s['lines'] as $l) {
                $out[] = $l['label'];
            }
            $out[] = '';
        }
        $out[] = $closing;
        return implode("\n", $out);
    }
}

if (!function_exists('radioTotals')) {
    /**
     * What the order is worth internally. Receivers = guests. Transmitters = one per departure
     * that has a guide (a departure without one still needs receivers, so it stays in the list).
     */
    function radioTotals(array $departures) {
        $receivers = 0;
        $transmitters = 0;
        foreach ($departures as $d) {
            $receivers += (int) $d['pax'];
            if (!empty($d['guide_id'])) { $transmitters++; }
        }
        return [
            'departures'   => count($departures),
            'receivers'    => $receivers,
            'transmitters' => $transmitters,
            'units'        => $receivers + $transmitters,
        ];
    }
}
