# Triage 2026-10-13 — "17:00 Florence (English)" on the 14 Oct unassigned report

**Symptom:** On 14 Oct 2026 the Unassigned Tours Report lists `17:00 Florence (English)`, while the
Tours page shows that departure ("Exclusive Evening Tour of Michelangelo's David", 4 PAX, GetYourGuide)
assigned to Caterina Cavalcaselle.

Checked on **production, read-only** (SELECTs only), 2026-10-13 19:55 Rome, right after near-sync 22300.

## Data
Only one non-cancelled booking on 14 Oct between 16:45 and 17:15:

| tours.id | product_id | title | lang | PAX | cancelled | tours.guide_id | group_id | tour_groups.guide_id | needs_guide_assignment |
|---|---|---|---|---|---|---|---|---|---|
| 6428 (GET-101165923) | 882285 | Exclusive Evening Tour of Michelangelo's David | English | 4 | 0 | **14** (Caterina) | **NULL** | — | **1** |

- The booking is **not in a group** (it is the only active booking of product 882285 at 17:00; its
  17:30 sibling 7650 is cancelled). So "tour guide vs group guide" does not apply: the departure is
  the single unit `t6428`, and its guide lives on the tour row.
- `products` row 882285: `product_type = 'tour'`, title "Exclusive Evening Tour of Michelangelo's David".
  The `products` table has **no museum column** (columns: bokun_product_id, title, product_type,
  duration_minutes, meeting_point). "Accademia" elsewhere comes from title keywords
  (`accademia` OR `david` in `lib/guide_report_core.php:96`, `tour_classification.php:60`).

## Report query now
`fwlUnassignedReport(date = 2026-10-14)` returns 6 departures: 12:00 g1508394, 12:30 g1508397,
14:00 t7812, 14:15 t7407, 16:15 t6179, 17:45 g1508337. **No 17:00 row.** `t6428` is dropped by
`HAVING MAX(tg.guide_id) IS NULL AND MAX(t.guide_id) IS NULL` (`lib/departure_queries.php:38`)
because `t.guide_id = 14`. The query is correct for this row; it cannot reproduce the line now.

## When / how Caterina was assigned
- No `assistant_actions` table exists on production → the assistant assign card was never used.
- No `payments`, no `guide_reminders` rows for 6428; no web-server access log on the host;
  `api-error.log` has nothing for 6428.
- `updated_at` / `last_sync` are re-stamped by every sync (17:55:03 = near-sync 22300), so they cannot date it.
- `needs_guide_assignment = 1` with `guide_id = 14` → the guide was set with the **single-booking
  edit on the Tours page** (PUT tours.php, which never clears the flag). `propagateGuideToTours`
  did **not** run and could not: it only runs for groups, and it sets the flag to 0.
- Conclusion: the report the owner saw was most likely downloaded **before** Caterina was saved.
  The report's own "Generated:" line will confirm it.

## Root causes
1. **Assignment line:** not reproducible; current data and query agree (no 17:00 line). Likely a
   report generated before the assignment. No audit trail exists to prove the time.
2. **Label "Florence":** `getLocation()` in `src/utils/unassignedReport.js:12-27` guesses the place
   from title keywords; "Exclusive Evening Tour of Michelangelo's David" has none of them → falls
   through to `'Florence'`. The rest of the system counts "david" as Accademia; this function does not.
   Same fallback today for "Renaissance Masterpiece David & Historic Florence Tour" (948427) and
   "Michelangelo Private Guided Tour" (850642).

## Proposed fix → new plan step 6.19 (not started)
- Label: make the report use the same Accademia rule as the server (`accademia` OR `david`) —
  smallest change, one line + a test. A true "products table" label needs a new `products.museum`
  column filled for the 28 products: a bigger, separate step if wanted.
- Assignment: no code change for the report (it already uses the group's guide, then the tour's).
  Optional: have the single-booking PUT clear `needs_guide_assignment` when a guide is set, and
  record guide changes (who/when) so the next question like this can be answered.
