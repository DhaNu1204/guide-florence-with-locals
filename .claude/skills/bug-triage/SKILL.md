---
name: bug-triage
description: Reproduce and root-cause a reported bug in the Tour Management System on staging (SQL + curl + browser) BEFORE writing any code, then turn it into a plan step. Use when the user reports "X is wrong/showing/missing" (e.g. unassigned list, missing GYG booking, wrong count, sync failed).
---

# Bug triage — reproduce first, then fix

## 1. Pin the symptom
Ask (or infer from the message) exactly one of: booking reference / tour id / date / guide name / screen + filter that shows the wrong thing. Write it as a one-line hypothesis-free statement: "On <date>, <screen> shows <X>, expected <Y>."

## 2. Look at the data, not the UI
Run against **staging** (fresh copy of prod; ask the user to refresh it if older than a week):
- Tours & groups for the date:
  `SELECT t.id, t.external_id, t.title, t.date, t.time, t.participants, t.language, t.guide_id, t.needs_guide_assignment, t.group_id, tg.guide_id AS group_guide, t.cancelled, t.product_id, p.product_type FROM tours t LEFT JOIN tour_groups tg ON tg.id=t.group_id LEFT JOIN products p ON p.product_id=t.product_id WHERE t.date='<date>' ORDER BY t.time, t.group_id;`
- Recent syncs: `SELECT * FROM sync_logs ORDER BY id DESC LIMIT 5;`
- For a missing booking: search `bokun_data` (`WHERE bokun_data LIKE '%<ref>%'`) and, if absent, call Bokun directly with `tools/bokun_probe.php <ref>` (create it if missing: uses `BokunAPI::getBooking`) to see whether Bokun has it and under which role/channel/product.
- Call the same API the screen uses with `curl` (admin token) and compare with the SQL. Differences between SQL and API → backend bug; between API and screen → frontend/cache bug (try with `localStorage` cleared and the network tab open).

## 3. Common known root causes (check these first — see TECHNICAL_ANALYSIS_2026-09-10.md)
- Tour has `guide_id NULL` but its group has a guide → regroup didn't propagate (plan step 3.5).
- Group missing from the groups API in date-range view → `tour-groups.php` ignores `start_date/end_date`, caps at 50 (step 5.2).
- Stale data → localStorage `tours_v1` cache poisoning (step 5.1) — reproduce by clearing storage.
- Booking flagged rescheduled every sync → `10:00:00` vs `10:00` (step 3.2).
- Ticket/tour misclassified → `products.product_type` row wrong or missing; title keyword filter still used in `guide-payments.php` / Dashboard.
- Sync `failed` on a quiet day → empty window treated as error (step 3.3).
- Booking not in Bokun at all → product not connected to Bokun (GYG direct) → step 6.4.

## 4. Output
A short triage note in `docs/triage/<date>-<slug>.md`: symptom, the exact rows/responses that prove it, root cause with `file:line`, and the fix as a **new or existing plan step id**. Then stop and hand over to `/implement-step <id>` — do not fix inside the triage.
