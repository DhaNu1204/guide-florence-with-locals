# Triage 2026-10-14 — 18 Oct 11:30 Uffizi group mixes English and Italian

**Symptom.** On production, the 2026-10-18 11:30 group of "Uffizi Gallery Guided Tour with Optional Vasari Corridor Visit" (product 1130528) holds t7241 (WEB-104410369, website, English, 3 PAX) together with t7802 (GET-106458986, GYG, Italian, 2 PAX) and t7839 (GET-106568223, GYG, Italian, 3 PAX). Expected (step 6.12): automatic groups are one language each.

**Root cause: not the sync.** Group 1508528 is a **manual merge** (`is_manual_merge = 1`, `bucket_key` NULL, created 2026-10-14 08:38:42 UTC = 10:38 Rome). Auto-grouping did the right thing at every step. The merge itself is allowed, but its "Mixed languages" warning never showed on desktop: in `TourGroup.jsx` the chip was the **last** item of the group row, after the fixed Guide (w-32) and Notes (w-56) columns. In the Tours table the title cannot shrink, so the row needs 1,507 px against 1,278 px visible at any screen width (content is capped at max-w-7xl). The chip sat at 1,380–1,482 px, always scrolled out of view.

## Evidence (production, read-only)

| When (UTC) | What | Proof |
|---|---|---|
| 2026-09-20 12:45 | t7241 booked for 08:30 | `tours.created_at`, webhook 4477 |
| 2026-10-12 16:18 | t7802 (Italian) booked for 11:30 | webhook 5611, `created_at` |
| 2026-10-13 11:17:52 | **Bokun** moves t7241 08:30 → 11:30 | webhook 5647 (CONFIRMED, booking 104410369) → sync 22160 `triggered_by=104410369` stamps `rescheduled_at` 11:17:54, `original_time` 08:30. Bokun payload: `startTimeStr` 11:30, `startTimeId` 4517425 (the same slot id as both GYG bookings), `customizedBooking` false. Not an edit in this app: the sync rewrites `time` from Bokun on every run, so an app edit could not survive anyway. |
| 2026-10-13 11:17 → 20:05 | 11:30 = one English + one Italian booking → no group | correct per 6.12 |
| 2026-10-13 20:05:21 | t7839 (Italian) arrives via webhook recheck; sync creates the Italian auto group (t7802 + t7839) | sync 22346 `recheck:106568223`; log "22:05:21 Rome Auto-grouped 14 tours into 1 groups"; that group is id **1508523**, now missing from the id sequence (1508522 → 1508524) |
| 2026-10-14 08:38:42 | Manual merge of all three → group **1508528**; 1508523 emptied and deleted | `tour_groups` row; log "10:38:42 Rome guides.php GET" in the same second = Tours page reload after an action |
| after | every sync leaves it alone (manual merges are never touched) | log "Auto-grouped … into 0 groups" for every run since |

Who merged: there is no `created_by` column. The only app activity in the window is `client_perf` 292 (user dhanu, Windows/Chrome, /tours, 08:29:20 UTC). User sudesh's next login was 09:00:58 UTC, after the merge. So most likely dhanu on the Windows PC, but this is not proven. The assistant was not involved: it only writes guides, there are no `assistant_logs` rows today, and production has no `assistant_actions` table. The note "msg 11:30" is not stored anywhere in our data (`tours.notes`, group notes and `bokun_data` are all empty).

## Every code path that can put a tour into a group

| Path | Language rule (6.12)? | Empty language |
|---|---|---|
| Sync `autoGroupAfterSync()` (cron, near, webhook, recheck, Sync now) | yes, `buildGroupBuckets` → `splitDepartureByLanguage` | joins only when exactly ONE known language is at that departure; otherwise stays alone (never forms a group) |
| Sync, existing group (incremental, 3.7) | yes: desired groups are language-split, matched by shared members; a group that is already mixed is **frozen** (`mixedLanguageAutoGroupIds`), never fixed | as above |
| Hand time/date change (`tours.php` PUT) | n/a: never writes `group_id`; the next sync re-buckets the booking (a Bokun booking's time is reverted to Bokun's by that sync) | n/a |
| Hand-entered tours (`source = manual`) | never auto-grouped | n/a |
| Tours "Auto-Group" button (`tour-groups.php` auto-group) | yes, same `splitDepartureByLanguage` | same rule |
| Manual merge / drag-and-drop (`tour-groups.php` manual-merge) | **no, by design**: the owner's decision; chip is the safeguard | n/a |
| Assistant | never writes `group_id` (guide assignment only) | n/a |

## Reproduced on staging
Staging (copy of 10 Oct; t7241 still 08:30 in English auto group 1508327) → gated real sync of 2026-10-18 → t7241 at 11:30 alone, Italian auto group 1508483 = t7763 + t7760 → `POST tour-groups.php?action=manual-merge` [7241, 7763, 7760] → 200, manual group 1508484, 1508483 deleted → sync again leaves 1508484 untouched → `GET tour-groups.php?date=2026-10-18` returns `language: "English, Italian"` with every member's language. Rendering both cards with that exact payload: phone card chip present, desktop chip present in the DOM but at 1,380–1,482 px of a 1,044 px (1366 screen) / 1,278 px (1920 screen) visible table.

## Fix → step 6.20
`TourGroup.jsx`: the chip moves from the end of the row to right after the departure time. Measured in a browser with the real payload: chip at 121–223 px, visible at 1024 px (702 px visible), 1366 px with the assistant open (576 px) and 1920 px (1,278 px). Regression test in `TourGroup.mixedLanguages.test.jsx` (fails on the old component). No backend change.

## Noted, not changed
- Phone card: the chip is hidden in selection mode (`!selectionMode` wraps that line).
- `/today` shows a mixed departure's languages as plain text ("English, Italian"), with no chip.
- A booking whose language changes in Bokun inside an auto group freezes that group as mixed instead of splitting it.
- The "Auto-Group" button keys by title (not product_id), does not skip private / no-product / hand-entered tours, uses a fixed 9 PAX cap, and in `force` mode never detaches leftovers.
