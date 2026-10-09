# Triage 2026-10-09 — last-minute GYG booking not on the Tours page after 16 min

## Symptom
On 2026-10-09 at 10:16 Rome, production Tours showed the 15:00 Uffizi Small Group departure (product 961801) as Kathryn Sleith alone, 1 PAX. Bokun showed 2/15: the second booking was GET-106159471 (GYG996RNBQFM, Laurence Faubert, 1 adult), created 08:00 UTC = 10:00 Rome.

## Timeline (production, read-only SELECTs; DB times are UTC, Rome = UTC+2)

| Rome  | What | Evidence |
|-------|------|----------|
| 10:00 | Booking created in Bokun | `bokun_data.creationDate` |
| 10:00:01–10:00:16 | Cron sync (60-day window): 688 found, **0 created** | `sync_logs` 20257 |
| 10:02:18 | **Bokun called the webhook**: HTTP 200, topic CONFIRMED, booking_id 106159471, dates_found 1, dates_processed 1, processed 1, no error | `bokun_webhook_logs` 5415 |
| 10:02:18–10:02:20 | Webhook 1-day sync for 2026-10-09: completed, **32 found, 0 created, 32 updated** (32 = the same count as the 09:54 webhook sync, so Bokun's booking-search did not yet return the new booking) | `sync_logs` 20258 |
| 10:11:00 | Owner's browser tab loaded `/tours` (complete, list ok) | `client_perf` 222 |
| 10:15:01–10:15:10 | Next cron sync: 690 found, **2 created** — the tour row appears | `sync_logs` 20261, `tours` 7708 `created_at` 08:15:09 UTC |
| 10:15:09 | Auto-grouped at once with Kathryn Sleith's booking 7585 in group 1508476 (`961801\|2026-10-09\|15:00\|English`, total_pax 2, no guide yet) | `tours.group_id`, `tour_groups` |
| 10:16 | Owner looks at the page loaded at 10:11 — no new data | — |

Answers to the four questions:
1. **Webhook:** yes, at 10:02:18, 2 min after creation. 200 (not 401/503), 1 date found / 1 processed, sync completed — but it did not create the booking.
2. **Sync after that:** the webhook's own sync (completed, 0 created) and the 10:15 cron (completed, created it). The cron runs every 15 min, so nothing ran between 10:02 and 10:15.
3. **Row first in DB:** 10:15:09 Rome, auto-grouped immediately with Kathryn Sleith (group 1508476, 2 PAX).
4. **Row in the DB before 10:16?** Only 51 s before. The tab open since 10:11 can never show it: since step 4.0 the browser starts no syncs and the Tours page loads its data once (no polling, no refetch on focus/visibility — `grep visibilitychange src` only finds `appUpdate.js` and `perfBeacon.js`). Without a manual reload the page stays at its load time indefinitely.

## Root cause 1 — the webhook's sync never sees a new GYG / website booking
`bokun_webhook.php` re-syncs the booking's date through `syncBookings()` → `BokunAPI::getBookings()` → `POST /booking.json/booking-search` **immediately**. For GYG and website bookings Bokun's search does not return the new booking yet at that moment.

Since 2026-09-20 (browser syncs gone, step 4.0), first CONFIRMED webhook per new booking, departures within 55 days:

| Channel | New bookings | Created by the webhook's own sync | creation → webhook p50 | webhook → row p50 / p90 |
|---|---|---|---|---|
| GetYourGuide | 395 (410 all dates) | **2** | 98 s | 410 s / 823 s |
| www.florencewithlocals.com | 25 (26) | **0** | 107 s | 342 s / 736 s |
| Airbnb | 25 (26) | 23 | 5 s | 1 s / 2 s |

For GYG/web every later sync covering the date found the booking (the only miss per booking was the webhook's own sync; other syncs found some as early as 1–40 s after the webhook). So it is a short search-index delay at webhook time, and in practice a new GYG/web booking waits for the next 15-min cron (p90 ≈ 14 min; departures more than 60 days out wait until they enter the cron window — max 18 days).

## Root cause 2 — an open page never refreshes
See question 4. Even with the row in the DB, an open Tours / Dashboard / Today page shows it only after a manual reload.

## Fix — step 4.11
- **4.11a (own commit, first):** the webhook, after answering Bokun, re-checks a CONFIRMED booking that its sync did not store and re-runs the 1-day sync a few times over ~2 minutes (connection already closed, so Bokun never waits).
- **4.11b:** CLI safety-net sync for today + tomorrow every 5 min, sharing one `GET_LOCK` with the webhook and the 15-min cron.
- **4.11c:** a tiny change-token endpoint the app polls every 60 s while visible; on change the page refetches in place and toasts new bookings / cancellations.
