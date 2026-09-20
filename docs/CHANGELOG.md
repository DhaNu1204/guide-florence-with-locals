# Changelog - Recent Major Updates

## ✅ STEP 6.2 — TWO TOURS, ONE GUIDE, ONE FEE (2026-09-20)
2026-09-20 step 6.2 — when an Uffizi tour and an Uffizi+Accademia tour go out together under one guide, the Daily P&L can now cost that guide once. Tick the two departures on the day view and press “Merge for costing”: they become one row marked “Merged — one guide”, with one guide fee (the existing precedence applied to the pair, never less than the dearest of them alone, and the row says which rule won) while tickets, radios, revenue and PAX still count per product and the breakdown stays visible. Unmerge puts both rows back exactly. New `pnl_unit_links` table + `pnl_links.php`; a departure can be in at most one link, links never cross a date, and they are never created automatically — auto-grouping still refuses to mix products. **P&L only: Tours, grouping, payments, guide reminders and the digest are untouched.** Checked first that manual merge already covers the common case (131 of 181 manual merges on production already span two products) — 6.2 exists for when the two departures must stay separate everywhere else. Staging proof on 2026-10-02: merging the two 13:00 Uffizi departures left revenue at €2,510.53 and tickets at €1,535.00 and cut the guide cost from €960.00 to €870.00 — exactly one fee; a real 821-booking sync then ran and the merge survived; unmerging restored every figure. 26 new PHP checks, vitest 171, smoke 12/12 on both, 0 links created on production.

## ✅ STEP 5.3 — A BATCH PAYMENT SAYS WHAT IT IS ABOUT TO WRITE (2026-09-20)
2026-09-20 step 5.3 (§2.10) — paying three tours at €50 records €150, and the screen used to show neither “per tour” nor €150. The field is now **“Amount per tour (€)”**, a live line under it reads **“3 tours × €50.00 = €150.00 total”** and the button reads **“Record €150.00”**, both following the selection and the amount. When the selection spans several guides the page lists the split per guide and **each departure is paid to its own guide** — the old form asked for one guide and recorded everything against it. The guide picker now appears only for departures that have none. `createPayment()` returns the row it wrote so the confirmation totals what the server actually stored; one row per tour unit, the group-aware duplicate check, the 409 and `force_group_payment` are untouched. Production check first (read-only): **0 payments in the last 90 days** (newest 2026-04-08); over all 88 payments, 14 batch-shaped clusters covering 35 rows, whose per-row amounts match each guide's usual single payment — **no accidental multiplication found**, and 0 payments were ever recorded against a guide who is not the tour's guide. Verified with 12 new vitest cases (171 total), a real 3-tour batch on staging that wrote exactly three €50.00 rows and was then deleted, and a read-only pass on production that showed “3 tours × €50.00 = €150.00 total” plus a three-guide split without submitting anything. Smoke 12/12 on both.

## ✅ STEPS 3.8 + 3.9 — PHASE 3 COMPLETE (2026-09-20)
2026-09-20 step 3.8 — `products.product_type` is now the only tour-vs-ticket rule in `guide-payments.php` (seven title-keyword blocks replaced). The two rules disagreed on **2,580 bookings**, all product 961802 (Uffizi Gallery Reserved Ticket + Digital Audio Guide), which the keywords waved through as guided tours; none of them currently has a guide, so **0 wrong rows were visible today** — the fault was latent. Every payment write now goes through one validator: `"abc"`, `0`, `-5`, `""` and `null` all answer **400** with a clear message on POST and PUT, a valid amount still works (201 / 200). Before, `"abc"` was stored as 0.00 and a bad PUT amount was silently ignored. `payment-reports.php` got the self-healing view guard `guide-payments.php` received in 4.1a.
2026-09-20 step 3.9 — a sync can no longer change data on a GET: `action=sync` / `full-sync` / `backfill-names` answer 405 with `Allow: POST`, and `POST action=sync` rejects an impossible or out-of-range date window with 400. `bokunAutoSync.js` (the “Sync Now” path) now POSTs. Deleted with zero references proved first: `BaseAPI.php`, `Logger.php`, `Validator.php` (the duplicate — the live one is in `Middleware.php`), `twilio_reminders.php`, `bokunDataExtractors.js`, `CardView.jsx`, `TourCards.jsx`, `UnpaidToursModal.jsx`, `UI/TourCard.jsx`, `UI/StatusBadge.jsx`. Left alone because they are referenced: `HttpClient.php` (BokunAPI's no-cURL fallback) and `autoAssignGuide()` (two live buttons on the Bokun page). The retired 60-minute reminder is fully gone; the three functions the digest needs moved to the new `twilio_helpers.php` and the digest is untouched.
Every figure identical before and after on both environments (production: 701 upcoming tours, 567 needing a guide, 379 groups, 1,201 pending payment rows, 88 payments / €6,859.92, P&L July €4,717.67 / €125.45); smoke 12/12 on both; the first production cron after the deploy ran in 9.81 s. **Phase 3 is complete.**

## ✅ STEP 3.7 — A DEPARTURE KEEPS ITS IDENTITY (2026-09-20)
2026-09-20 step 3.7 — auto-grouping is incremental. It used to detach every auto-grouped tour in range, delete the orphans and INSERT a brand-new row for each departure, so a departure got a new `group_id` every 15 minutes: production had burned **1,507,124 ids** for 1,098 live groups and **10 of the 11** P&L overrides keyed on `'g<id>'` were already dead (the 11th survived only because it points at a manual merge, which auto-grouping never recreates). Now each departure keeps its row: the group that shares the most members wins it, only the tours that actually moved are written, a group row is updated only when a value really changed, and a group is deleted only when its departure is gone. New `tour_groups.bucket_key` (`product_id|date|HH:MM`) gives a departure a natural identity, and `pnl_tour_costs.bucket_key` is stored beside `tour_unit` so an override survives even if an id ever moves again. Manual merges are still never touched. **Production, the two cron syncs after the deploy: `Auto-grouped 332 tours into 0 groups` (the last old-code run: 332 into 118), 0 tours changed group id, duration unchanged at 10.4 s / 9.5 s.** Staging, three consecutive syncs: `rows_written = 0` every time and 0 group ids changed — the same measurement gave 359 changed ids, 131 groups deleted and 137 created with the old code. 23 pure checks + 25 end-to-end scenarios against a real database, vitest 159, smoke 12/12 on both; every figure (tours, unassigned, groups, P&L, payments, digest) identical before and after. Open: the 10 already-orphaned overrides cannot be re-pointed safely (their dates carry several departures each and the row never stored the product or time) — saved to `~/backups/pnl_orphans_prod_20260920.tsv`, awaiting the owner's decision.

## ✅ STEP 4.1b — A FAILED ROUTE CHUNK NO LONGER STRANDS THE USER (2026-09-20)
2026-09-20 step 4.1b — the owner's phone hit “Something went wrong” opening Tours on mobile data (Sentry 148176284, `TypeError: Importing a module script failed.`). Triaged first: the stale-chunk theory was **ruled out** (production still pointed at the build the phone was running and all 12 of its chunks were on disk and serving 200), leaving a dropped request on a weak link — fatal because `React.lazy` had no retry and “Try Again” only re-rendered into the same cached rejection. Now: one `lazyWithRetry()` for all 11 routes (retry at 300 ms and 900 ms, then **one** `sessionStorage`-guarded reload, never a loop), “Try Again” reloads the page, `deploy.sh` retires hashed assets over **two** deploys instead of one, and two further faults found by breaking a chunk on staging are fixed: `sw.js` no longer caches the SPA fallback (HTTP 200 `text/html`) under an asset URL, and `.htaccess` no longer stamps failed asset responses as `immutable` for a year. Proved on staging: a tab whose chunk was deleted under it reloaded once and landed on the right page with the new build; a tab left open across a deploy loaded the retained old chunk with no reload at all. 8 new vitest cases, 159 green, smoke 12/12 on both. Still open: how often the Sentry issue fired and for how many users — needs the owner (no Sentry API token here).

## ✅ STEP 3.4 — A FULL SYNC TAKES SECONDS, NOT MINUTES (2026-09-19)
2026-09-19 step 3.4 — the Bokun sync stopped asking Bokun for a product it already had: the language ladder reads `productBookings[0].rateTitle` straight from the booking (present in 4,000/4,000 stored payloads) and the fallback `getProduct()` is cached per product per process (`getProductCached()`), and the per-booking existence lookup `bokun_booking_id = ? OR external_id = ?` (which no index could serve — `type=ALL`, 65 MB scanned ≈810 times per sync) became two indexed lookups plus the new `idx_tours_bokun_booking_id`. **Production: median 190.7 s → 9.19 s and 14.65 s; 764 Bokun requests per sync → 12; the 60-second rate-limit stalls (634× on 17 Sep, 377× on 18 Sep) → 0.** Manual Sync Now now returns HTTP 200 in 6.44 s through the proxy, as a single run — no step 3.4a needed. Nothing about what the sync writes changed, and it was proved rather than assumed: 812/815 bookings get an identical rate title and **0** a different language; 1,000 bookings pick the identical row with the split lookup; full per-column snapshots of all 7,198 tours around an old-code sync and a new-code sync show the same columns moving (`last_sync`/`updated_at` 815, `group_id` 374 — step 3.7 —, `bokun_data` 38–57) and `language` on 0 rows. Every sync now logs `bokun_requests / product_calls / product_cache_hits / rate_limit_sleeps`. vitest 151 + 16 new PHP checks green, smoke 12/12 on both environments.

## ✅ STEP 3.10 — EVENING GUIDE DIGEST LIVE (2026-09-19)
2026-09-19 step 3.10 (go-live) — `DIGEST_LIVE=true` on production (one authorised env line, backup kept, mode 600), both cron entries in place (`30 19 * * *` + `30 20 * * *` UTC, the Rome window guard picks the 21:30 run); the ~60-minute per-tour reminder is retired (`reconcileGuideReminders()` returns immediately, nothing is scheduled ever again) and its future Twilio messages were cancelled — verified: **28 cancelled, 0 failed**, a 13-page sweep of the account now shows **0** scheduled, `guide_reminders` 354 rows all `canceled`, `health.php` 200, smoke 12/12; tonight's 21:30 Rome run will send **6 digests for 7 departures from 19 bookings** (the old path would have sent 7 messages).
2026-09-18 step 3.10 (part 1) — one WhatsApp per guide per evening listing that guide's departures for the next day: `guide_digest.php` (departure-level, effective guide, tickets/cancelled excluded), `guide_digest_cron.php` (21:30 Europe/Rome via a DST-safe UTC window), `guide_digests` table (one row per guide per date, `sent` final, at most one retry), `tools/digest_preview.php` + `tools/digest_check.php` — **deployed to staging and production but sending nothing**: `DIGEST_LIVE=false` (default) refuses every destination except the test number before any Twilio call, and no cron entry exists yet. Verified: staging dry-run proofs (3 bookings → 1 line; 2 departures → 1 message with 2 lines; 4 guides → 4 separate messages; no tours → nothing; re-run → `already_sent`, no second send), 30 pure checks + vitest 151 green, smoke 12/12 on both; production preview for tomorrow = 4 messages instead of 5 per-departure reminders. Measured before: worst single guide-day 5 messages, 323 reminders ended `canceled` (321 after their send_at), 0 guides with an unusable phone. Approval test 2026-09-18: Content SID added to the production env (one authorised line, backup kept, mode 600, health 200); the first send was rejected by Twilio (21656 - a template parameter may not contain a newline) so the tour lines are now comma-joined like the approved template sample, and `digestVerifySent()` was added because Twilio accepts the call and fails the message later (the row had said `sent` for a message that never arrived); the retry was **delivered** and the test rows were removed. Waiting on the owner: "go live" (flip `DIGEST_LIVE`, add the cron, retire the 60-minute reminder and cancel its 33 future Twilio messages).

## ✅ STEPS 3.6a + 5.2 — THE "NEEDS A GUIDE" NUMBERS ARE TRUE (2026-09-18)
2026-09-18 step 3.6a — a tour merged by hand into a departure that has a guide now gets that guide at once (`syncGroupGuideFromTours()` → shared `fillMissingGroupGuide()`, fill only); one-off fill of the upcoming guide-less members of assigned manual merges, ids saved first: production 8 rows → 0 left, staging 0; the ~305 past rows deliberately untouched (owner decision) — verified: staging manual merge via the API gave the joiner guide 19 immediately and a later real sync left that manual group unchanged.
2026-09-18 step 5.2 — `tour-groups.php` understands `start_date`/`end_date`/`past`; the Tours page loads ALL groups for its filter (100 per page, total checked), shows an error state when the group request fails, and the banner "N tours still need a guide" is the unassigned report's own server total (`count_only`) — verified: banner = report = manual SQL in Today / Upcoming / All / 3-week range: staging 5 / 164 / 619 / 88, production 1 / 131 / 579 / 68; date-range view went from 0 to 45 (staging) / 55 (production) group rows with their FULL badges and no booking lost (247 = 247, 283 = 283); production Upcoming banner 156 → 131; console clean, vitest 151 green, smoke 12/12 on both.

## ✅ STEP 3.5 — THE UNASSIGNED-LIST BUG (2026-09-18)
2026-09-18 step 3.5 — a booking that joins an already assigned departure now receives the group's guide after the sync (`fillMissingGroupGuide()`, fills only guide-less members, never overwrites); the unassigned report is computed on the server per departure with the effective guide (`tours.php?action=unassigned-report`), and the Bokun page's unassigned list uses the effective guide too — verified: staging simulated joiner got guide 34 back after a real sync (`guides_filled: 4`); report = manual SQL count on 4 filters (staging 163 / 5 / 87 / 59, production 131 / 1), a just-assigned tour and group left the report immediately (30 → 29 → 28); production first cron after deploy: guide-less members of assigned groups 36 → 8 (the 8 are in manual merges → 3.6), guide-less bookings 216 → 188; vitest 143 green, smoke 12/12 on both.

## ✅ STEPS 3.2 + 3.3 — REAL RESCHEDULES ONLY; EMPTY SYNC IS NOT A FAILURE (2026-09-18)
2026-09-18 step 3.2 — reschedule detection compares normalised `HH:MM` / `Y-m-d` (`10:00:00` vs `10:00` was always "changed", so every booking was flagged and `rescheduled_at` rewritten on every sync); "Rescheduling detected" log line unconditional again — verified: staging same window synced 3×: run 1 logged 3 real reschedules, runs 2 and 3 stamped 0 rows (618 / 693 B of log); production first cron after deploy: completed, 827 found, 191 s, 0 rows with a new `rescheduled_at` (826 the run before), log growth 517 B; `tools/reschedule_check.php` 15/15. Data repair run after the owner's confirmation (ids saved to `~/backups/reschedule_repair_*` first): staging 6,378 rows reset / 336 genuine kept, production 6,528 reset / 351 genuine kept.
2026-09-18 step 3.3 — an empty Bokun search (both roles answered, no bookings) returns `[]`: the sync is `completed` with 0 found and `autoGroupAfterSync` still runs; legacy endpoints only after a real role failure, which still ends `failed` — verified: staging far-future date → `completed` / 0 found; stale empty group on a quiet date removed by syncing that date; wrong secret on the same date still throws; production last 7 days had 5 `failed` rows, all 5 empty-result webhook syncs; smoke 12/12 on both.

## ✅ STEP 3.1 — SYNC LEAVES LOCAL PAYMENT STATE ALONE (2026-09-18)
2026-09-18 step 3.1 — the Bokun sync UPDATE no longer writes `paid`, `payment_status`, `total_amount_paid`, `expected_amount` (INSERT only, `paid` always 0); Bokun's customer price lives in new `tours.bokun_total_price` / `bokun_currency` (self-provisioned + backfilled: staging 7,031 rows, production 7,198, 0 mismatches vs the PHP mapper); green badge now reads "Guide paid" and comes from a server-computed `guide_paid` (payments table, group-aware), the customer amount is a labelled "Customer paid" line in the details modal — verified: staging proof with a real sync (`paid=1 / partial / 67.89` and `paid=0 / 123.45` survived, blanked price restored), production counts + P&L totals identical before/after, cron check: snapshot of the four columns for all 7,198 tours at 18:54:52Z, first cron sync after the deploy (sync_logs 17148, completed, 827 found / 826 updated / 1 created, 137 s): 826 rows re-synced, 1 new booking inserted as paid=0 / unpaid / 0.00 / 0.00 with bokun_total_price 297.72 EUR, **0 rows** changed in paid / payment_status / total_amount_paid / expected_amount; all 827 synced rows carry a price, smoke 12/12 on both, vitest 136 green. Finding: production never had a wrongly green badge (0 tours with `paid=1`) — the old mapper read a top-level `totalPrice` Bokun does not send, so the sync was resetting the four columns to zero instead.

## ✅ STEP 4.1a — HOTFIX: PAYMENT VIEWS RESTORED (2026-09-18)
2026-09-18 step 4.1a — production had lost both payment views (`guide_payment_summary`, `monthly_payment_summary`; present in the 2026-09-10 dumps, gone by 2026-09-17 21:44, most likely an hPanel database operation on 2026-09-16 — unproven); recreated from the staging definitions with `CREATE OR REPLACE VIEW`; `guide-payments.php` now recreates its view once on error 1146 and retries, other errors untouched — verified: `guide-payments.php` 500 → 200 (30 rows), monthly report 200, /payments renders with figures, /daily-pnl + /guide-reports fine, guard exercised on staging (view dropped → 200, recreated, one log line), smoke 12/12 on both.

## ✅ STEP 4.1 — CODE-SPLIT THE BUNDLE (2026-09-18)
2026-09-18 step 4.1 — `React.lazy` for all 11 page routes behind one Suspense spinner, jsPDF/jspdf-autotable loaded on demand inside the export handlers ("Preparing…" state), Sentry Session Replay no longer shipped in production (`tracesSampleRate` 1.0 → 0.1, no `showDialog`), `vendor-react` manual chunk — verified: main chunk 1,471.6 KB raw / 355.4 KB br → 236.1 KB / 65.3 KB (77.0 KB gzip); first-paint wire bytes production 421,021 → 138,884 B (−67 %), staging 421,064 → 138,896 B, all `br` + `immutable`; Lighthouse mobile on a local preview of the same build 80 → 98 (FCP 3.0 → 1.7 s, LCP 3.2 → 1.8 s, TBT 330 → 120 ms, 441 → 150 KiB) — Lighthouse against staging/production is blocked by the host WAF (403 to headless Chrome); browser walk-through of all 11 pages on both environments: per-route chunks only, PDF chunks only on click, 0 console errors, 0 CSP violations, 0 asset 404s; smoke 12/12 on both; vitest 125 green. Pre-existing bug found (not from this step): production `guide-payments.php` 500 — missing `guide_payment_summary` view.

## ✅ STEP 4.0 — NO BROWSER-STARTED SYNCS (2026-09-18)
2026-09-18 step 4.0 — the app never starts a Bokun sync on its own: startup, focus/visibilitychange and 15-min periodic triggers removed from `bokunAutoSync.js` and the `useBokunAutoSync` hook; manual Sync Now, cron and webhook unchanged; `sync-info` returns `last_sync` (latest completed `sync_logs` row) and the label polls it at most every 5 min; Bokun page texts informational; stuck `started` rows > 1 h marked `abandoned` (staging 2,694, production 3,867) — verified: vitest 124 green, staging + production browser 0 `action=sync` requests after 75–104 s incl. tab switch / focus / visibilitychange, Sync Now = 1 request, label = cron time, smoke 12/12 on both; production `started` rows in the last hour 8 before → 12 at +31 min (2 from the one manual test click, the rest from clients still on the old bundle); open: 24 h trigger check, and Sync Now does not complete on production (hosting edge retries the long GET after 55 s and both runs are killed — pre-existing, 3.3/3.9); hPanel: delete the cron entry that uses `/usr/bin/php` (PHP 7.4).

## ✅ STEP 4.2 — LIGHT TOURS LIST (2026-09-18)
2026-09-18 step 4.2 — `tours.php?view=list` (33 fields, no `bokun_data`; PAX total, start time and language derived server-side), `tours.php/{id}?view=full` for the details modal (fetched on open, spinner, fallback notice), Tours + Dashboard use the list view, default API output byte-identical; Phase 3 postponed, order is 4.2 → 4.1 → 4.3 — verified: production upcoming list 3,731,899 B raw / 797,339 B br → 431,883 / 57,951 B (455 rows both, 0 field mismatches), staging 3,104,868 / 663,216 → 352,716 / 46,837 B (376 rows), rendered /tours text identical (staging before/after, production same-minute A/B 374 = 374 rows), dashboard counts unchanged, modal all sections, smoke 12/12 on both, vitest 116 green.

## ✅ STEP 2.2 — SERVER .ENV OUTSIDE THE WEB ROOT (2026-09-17)
2026-09-17 step 2.4 — Phase 2 complete. One CI workflow `ci.yml` (push to master + pull_request: php-lint 8.2, frontend Node 20 test + build, gitleaks), `deploy.yml` deleted, no deploy from CI; Bokun per-request/per-response and per-booking rescheduling log lines only with `BOKUN_DEBUG_LOG=true`; production error log rotated — verified: PR #1 all three jobs green, staging one Bokun request = 0 bytes of log, production cron slot 22:15 Rome wrote 1.9 KB (was ≈440 KB), smoke 12/12 on both
2026-09-17 step 2.3 — one site `.htaccess` (`public/` → `dist/`, inherited by `/api/`): HTTPS redirect, HSTS, nosniff, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy set once with `Header always set`, enforcing CSP on `index.html` only, `/assets/*` immutable, `index.html` no-store, `sw.js`/manifest no-cache; API `.htaccess` trimmed to deny + rewrites; `config.php` without `ob_gzhandler` and duplicate header calls; `index.html` inline `<style>` removed; package version baked into the bundle as the Sentry release — verified: curl on `/`, `/index.html`, `/assets/index-*.js`, `/api/health.php` shows every header ×1 on staging and production, CSP walk-through (report-only then enforcing) with 0 violations, normal reload after a deploy loads the new `index-*.js` hash on both environments, smoke 12/12 ×2
2026-09-17 step 2.2 — `EnvLoader` reads `FWL_ENV_FILE`, then the per-site file outside every web root (`~/env/<site>/.env` on Hostinger, because the parent of the document root is the domain's shared web root), then the legacy locations; first file wins; `health.php` shows `env_source`; `tools/env_which.php` — verified: staging and production switched inside_webroot → outside_webroot with zero downtime (copy first, verify, then move the in-tree files to `~/backups`), smoke green at each stage, app loads, `/.env` and `/api/.env` 403, cron path resolves the new file.

## ✅ STEP 2.1 — CONFIG HARDENING (2026-09-17)
2026-09-17 step 2.1 — `config.php`: PHP error log outside the web root (`FWL_LOG_DIR` / `<home>/logs/api-error[-env].log`), EnvLoader is the only env parser, strict CORS (allowed origins only, no fallback header, plain-http origin dropped, preflight 204, `Vary: Origin`), MySQL session pinned to UTC after connect while PHP stays Europe/Rome on purpose — verified: staging + production evil origin → no ACAO, own-origin preflight 204 with headers, log files receiving lines, staging counts identical before/after (402 tours, 439 unassigned, 42 reminders), smoke green, app loads and syncs, vitest 109/109.

## ✅ STEP 1.6 — ENCRYPTION FAIL-CLOSED, PHASE 1 COMPLETE (2026-09-17)
2026-09-17 step 1.6 — ciphertext prefixed `enc:v1:` with HKDF-derived cipher/MAC keys, `isEncrypted()` tests the prefix only, `encrypt()` throws without a key and saving Bokun config then returns 500 instead of storing plaintext; legacy values still decrypt; rows re-encrypted with `--action=prefix` on staging and production; `tools/encryption_check.php` — verified: check 200/200, unprefixed rows 0 on both, masked key unchanged, production manual sync 16489 completed 839/839, smoke green, vitest 109/109. Phase 1 (1.1–1.6) is complete.

## ✅ STEP 1.5 — REAL LOGOUT + HASHED SESSION TOKENS (2026-09-16)
2026-09-16 step 1.5 — `POST auth.php?action=logout` deletes the session server-side; `sessions.token` now stores sha256(token) (marker `session_id = 'sha256:<hash>'`, raw rows rewritten in place on first use + migration `20260916_hash_session_tokens.sql`); `AuthContext.logout()` calls the endpoint before clearing, `ModernLayout` uses it and the header shows the context name; `ProtectedRoute` requires a stored token so Back after logout lands on /login — verified: staging + production login row is a 64-hex hash, tours 200 → logout 200 → same token 401, unmarked rows 0, existing sessions kept, smoke green, vitest 109/109.

## ✅ STEP 1.4 — BOKUN WEBHOOK SECRET + CAPS (2026-09-16)
2026-09-16 step 1.4 — `bokun_webhook.php` requires `?key=<WEBHOOK_SECRET>` (hash_equals; 503 when unset, 401 when wrong, no log row), processes at most 3 distinct dates per event (`dates_found`/`dates_processed`), stores at most 64 KB of payload (`...[truncated]` marker in a JSON wrapper); helpers in `webhook_helpers.php` + CLI check — verified: staging 401/401/200/200(50→3)/200(65536+marker), production 401/401, smoke green on both, vitest 105/105.

## ✅ STEP 1.3 — RATE LIMITER THAT CANNOT BE BYPASSED (2026-09-16)
2026-09-16 step 1.3 — client IP = REMOTE_ADDR (proxy headers only behind `TRUSTED_PROXIES`), atomic counting in `rate_limits`, `login_attempts` self-provisioned, failed logins limited per IP (5/min) and per username (10/15 min) with 429 + Retry-After and a neutral body, success clears the username counter; `tools/ratelimit_probe.php` for verification — verified: staging 6 forged-header logins → 429, 11th attempt on one username → 429 (734 s), correct login after the window → 200 + counter cleared; production table created on first login, smoke rows green, vitest 105/105.

## ✅ STEP 1.2 — BOKUN SECRET NO LONGER LEAVES THE SERVER (2026-09-16)
2026-09-16 step 1.2 — `action=config` (GET and POST) answers only the masked shape {configured, sync_enabled, vendor_id, last_sync, api_key_masked, updated_at}; one config row (leftover plaintext rows backed up and deleted), saves update in place and empty key fields keep the stored keys; auto-sync calls `action=sync` directly and treats {success:false,error:'sync_disabled'} as a skip (attempt-throttled); Bokun Integration form shows the masked key with write-only key fields — verified: staging + production config/sync-info bodies contain no key or secret, 2 requests per 15 min in the browser, cron 848/848 after cleanup, vitest 106/106.

## ✅ STEP 1.1 — SERVER-SIDE ROLES (2026-09-16)
2026-09-16 step 1.1 — admin role enforced server-side: `Middleware::requireAdminForWrites()` on tours/guides/tickets/tour-groups/payments/guide-payments/payment-reports/guide-tour-report + guide-requests owner branch; `bokun_sync.php` admin-only except GET sync-info/unassigned; uniform 403 JSON; `AdminRoute` for /bokun-integration and /daily-pnl, role/name from `useAuth()`, one "You don't have permission for that" toast on 403 (session kept), viewer never triggers the 15-min sync — verified: staging + production smoke (viewer DELETE/POST/PUT/sync/config → 403, GET → 200, admin writes 200/201, public token route unchanged 404), vitest 99/99.

## ✅ STEP 0.3 — SECRET PURGE + CREDENTIAL ROTATION (2026-09-16)
2026-09-16 step 0.3 — secrets, customer PII and stale artefacts removed from the tree (27 files) and from git history (`git filter-repo`, 15 secret strings, force-pushed; GitHub default branch is now `master`, `main` deleted — re-clone old copies); kept SQL files are DDL only; `tools/seed_admin.php` (CLI, `--password-stdin`); admin/viewer/DB/SSH/Bokun/ENCRYPTION_KEY/Sentry credentials rotated (`Encryption::initWithKey`, `migrate_bokun_credentials.php --action=rekey`); gitleaks guard: `.gitleaks.toml`, `scripts/pre-commit`, CI job in `main.yml` (history scan: 0 leaks).

## ✅ STEP 0.2 — REPO HYGIENE + SAFE DEPLOY (2026-09-11)
2026-09-11 step 0.2 — `api/health.php` ({ok,db,env,sha,time}); maintenance scripts moved to `tools/` (CLI guard) and out of the web root; Bokun header/raw-response logging removed; deny blocks in both `.htaccess` (.env*, logs, sql/md/json/txt/bak/backup, VERSION, maintenance/test scripts, tests/); `deploy.sh` rewritten (mandatory --target, dirty tree refused, production only from master, `npm ci`, backend allowlist from `git ls-files` + VERSION, frontend sync without root delete, backups keep-5 by name, `--restore-last-backup`, health = sha match) — verified: staging + production health 200 with sha 82fc41f, all denied URLs 403/404, smoke rows 1–5/11 green (6–8 expected-fail pre-1.x), second deploy 0 files changed and no mtime churn, .env files intact; production backed up first (`~/backups/pre-0.2-20260910_215631.{tgz,sql}`).

## ✅ STEP 0.1 — STAGING ENVIRONMENT (2026-09-10)
2026-09-10 step 0.1 — staging at https://stagingwithlocals.deetech.cc (fresh prod DB copy, reminders/availability truncated); `deploy.sh --target staging|production` (production only from master + clean tree); `APP_ENV=staging` in config.php (= production behaviour + staging CORS origin); `BOKUN_SYNC_ENABLED` env gate; `TWILIO_DRY_RUN` writes "DRY RUN: …" to guide_reminders.last_error instead of calling Twilio — verified: no-token 401 / admin 200 (25 tours), sync-info `sync_enabled_env:false`, sync refused `sync_disabled`, 1-week sync completed (197 updated, 26 s) with 27/27 dry-run reminders and 0 real Twilio sids, production files + DB counts unchanged. Not deployed to production (ships with 0.2).

## ✅ DASHBOARD COMPACTION (2026-07-17)
✅ DEPLOYED (6a130fd) — dashboard sections collapsed by default with "Show all (N) ▾ / Show less ▴" toggles: needs-guide alert previews 3, recent responses 3, Upcoming Tours 5, Needs Attention 5. Display-only. New `Dashboard.collapse.test.jsx` (4 interaction tests → suite 91).

## ✅ PWA — INSTALLABLE APP (2026-07-15)
✅ DEPLOYED (544fd71) — installable on iOS/Android home screens ("FwL Tours", terracotta Duomo icon). New `public/manifest.webmanifest`, `public/sw.js` (never intercepts `/api/`; navigations network-first so deploys appear immediately; assets stale-while-revalidate), `public/icons/`; iOS meta in index.html; SW registration prod-only in main.jsx; `.htaccess` manifest MIME + sw.js no-cache. **GOTCHA discovered: deploy.sh does not ship `.htaccess`** — upload manually when it changes.

## ✅ DAILY P&L — ITERATIONS (2026-07-06 → 07-08)
Six follow-up deploys on the P&L tracker (all NO payment logic):
- **Day-view redesign** (20784a7): flat table → sectioned cards (Guided Tours by category / Tickets & Audio / Cancelled) with EditableChip inline overrides and per-section in/out/profit subtotals.
- **Outsourced flag + Uffizi PM pricing** (7a745a6): "Given to agency?" toggle per unit (ticket kept + `outsource_fee`, guide/radio/gelato €0; new `pnl_tour_costs.outsourced` column, SHOW COLUMNS guard); `ticket_uffizi_*_pm` settings used when booking time ≥ 16:00; business defaults (Uffizi 29 / PM 20 / Accademia 20 / fee 10) for never-saved keys.
- **Borghese + unit split** (a14ece0): 'Borghese' museum/category (teal badge, adult+child both default €17 — no child ticket); totals split `tour_units`/`ticket_units` ("N tours · M tickets" — ticket products no longer counted as tours).
- **Week view + profit by product** (03e3ef7): Day|Week|Month toggle, Mon–Sun week nav, `by_category[]` in range API, CategoryTiles in week+month views, overhead footer month-only.
- **Private guide rate** (2518807): `guide_rate_private` (€240 = 4h × €60/h; shared combo €210 = 3.5h); `is_private` units use the flat private rate; purple Private badge. Cost precedence: outsourced > ticket > private > Mixed(highest member) > category.
- **CostDetailModal** (aa07b10): tap any card → mobile bottom-sheet popup showing the calculation formulas (per-museum tickets incl. PM variant, guide-rate source, per-person radio/gelato) with editable fields, per-field Auto reset, agency toggle, notes, live profit preview. Chips hidden on mobile ("Tap to see & edit costs ▸").

## ✅ DAILY P&L TRACKER — ADMIN ONLY (2026-07-06)

### New Feature — per-day profit & loss over tour units
✅ COMPLETED & DEPLOYED (2d8bfbf) — admin-only page showing revenue, costs, and profit per tour unit (group or standalone booking) per day, plus a month summary. **Touches NO payment logic** — reads tours/groups read-only and writes only to its own self-provisioned tables (`pnl_settings`, `pnl_tour_costs`).

- **Revenue**: auto-extracted per booking from stored `bokun_data` — `resellerInvoice` (retail / commission / net) preferred, then `sellerCommission` + `customerInvoice`/`totalPrice`, else channel commission % estimate (flagged `estimated`, shown with "~"). Cancelled bookings excluded.
- **Auto costs** from configurable rates: museum tickets per adult/child (per museum in each booking's title), guide rate per tour category (a Mixed merged group pays the highest member-category rate), radio per person, gelato per person (gelato tours only). Ticket/audio products get no guide/radio cost.
- **Manual overrides**: click any cost or Net cell in the day table to enter your own amount (terracotta = manual, ↺ resets to automatic). `null` clears an override (`array_key_exists` pattern).
- **Month view**: per-day totals, click into day, monthly overhead (staff/office/other from settings) and profit-after-overhead footer.

### Files
- **Backend** (1 new): `api/pnl.php` — `Middleware::requireRole($conn, 'admin')`; `GET ?date=` day detail, `GET ?start=&end=` range summary (max 92 days), `GET/POST ?action=settings` (whitelisted keys), `POST ?action=costs` (per-unit override upsert). Tour unit key `g<group_id>` / `t<id>` — same convention as the payment system.
- **Frontend** (1 new): `src/pages/DailyPnL.jsx` — Day/Month views, summary cards, editable-cell day table, Rates & Costs settings modal.
- **Modified**: `App.jsx` (route `/daily-pnl`, protected), `ModernLayout.jsx` (sidebar item, `adminOnly` filter on `userInfo.role`), `mysqlDB.js` (`getPnlDay`, `getPnlRange`, `getPnlSettings`, `savePnlSettings`, `savePnlCosts`).

### Verification
| Test | Result |
|------|--------|
| `php -l` pnl.php | No syntax errors |
| `npm test -- --run` | 87/87 pass |
| `npm run build` | OK |
| Local end-to-end (dev DB, login dhanu) | Day 2026-05-27: 2 units, net €407.77 from real Bokun invoices (non-estimated); settings save recomputes guide cost; cost override persists + resets; Month view renders with overhead footer |
| Production | frontend 200; `pnl.php` 401 unauthenticated (live, admin-auth enforced); bundle `index-CH1-Fp9r.js` matches build |

---

## ✅ IDEMPOTENT TICKET CLASSIFICATION FIX (2026-03-03)

### Bug Fix — Borghese Gallery ticket appearing on Tours page
✅ COMPLETED - Fixed product 1162586 ("Borghese Gallery Entry Ticket and Audio Guide") showing as a tour instead of a ticket

- **Root cause**: The known ticket classification (`UPDATE products SET product_type = 'ticket'`) only ran inside a one-time migration block (`if ($checkProductIdCol->num_rows === 0)`). Product 1162586 was synced *after* the migration had already completed, so `bokun_sync.php` auto-registered it as `'tour'` via `INSERT IGNORE` and it was never reclassified.
- **Symptom**: Borghese Gallery Entry Ticket appeared on the Tours page alongside real tours (e.g., on April 6 alongside "Private Tour in Bargello Museum").

### Fix
- **Moved** the known ticket classification **outside** the one-time migration block in `tours.php`
- **Changed** from `UPDATE ... WHERE IN (...)` to `INSERT INTO products ... ON DUPLICATE KEY UPDATE product_type = 'ticket'`
- This idempotent query runs on every request to `tours.php` and handles all cases:
  - Products not yet in the table → inserted as `'ticket'`
  - Products mis-classified as `'tour'` → corrected to `'ticket'`
  - Products already `'ticket'` → no-op

### Files Modified
- **Backend** (1 file): `tours.php` (moved classification outside migration block, changed to INSERT...ON DUPLICATE KEY UPDATE)

### Production Verification
| Test | Result |
|------|--------|
| Products table — all 7 ticket IDs | All marked as `ticket` |
| Tours API — April 6 (product_type=tour) | 1 tour (Bargello, 2 PAX) — Borghese excluded |
| Tours API — April 6 (product_type=ticket) | 1 ticket (Borghese, 2 PAX) — correctly classified |
| Total products in table | 24 (17 tours, 7 tickets) |

---

## ✅ AUTH FIX FOR PAYMENTS PAGE (2026-02-25)

### Bug Fix — fetch() calls missing Authorization header
✅ COMPLETED - Fixed 401 errors on Payments page, Dashboard pending count, PaymentRecordForm, and ticket operations

- **Root cause**: Security hardening (2026-02-24) added `Middleware::requireAuth()` to all API endpoints. However, several components used raw `fetch()` instead of `axios`, bypassing the axios interceptor that adds the `Authorization: Bearer <token>` header. These requests were sent without authentication and rejected with 401.
- **Symptom**: "Error loading payment data: Failed to load payment overview" on the Payments page in production. Dashboard "Guide Payments Pending" count also failed silently.

### Files Fixed
- **Payments.jsx**: 10 `fetch()` → `authFetch()` (overview, summaries, pending tours, guides list, record payment, guide details, reports x2, edit transaction, delete payment)
- **Dashboard.jsx**: 1 `fetch()` → `authFetch()` (pending payments count)
- **PaymentRecordForm.jsx**: 2 `fetch()` → `authFetch()` (load tours by date, submit payment)
- **ticketsService.js**: 2 `fetch()` → `authFetch()` (delete ticket, update ticket) + removed stale axios interceptor that read wrong key (`authToken` instead of `token`)

### Fix Pattern
Added `authFetch()` wrapper in each affected file — injects `Bearer <token>` from `localStorage.getItem('token')` into every `fetch()` request, matching the axios interceptor behavior in `mysqlDB.js`.

---

## ✅ PRODUCT CLASSIFICATION SYSTEM (2026-02-24)

### DB-Driven Product Type Filtering
✅ COMPLETED - Replaced fragile keyword-based ticket filtering with reliable product ID classification

- **Problem**: Ticket products (museum entry tickets, audio guides) were filtered from the Tours page using 5 `NOT LIKE` keyword patterns matched against tour titles. This was brittle — new ticket products with different titles would slip through, and the same 45 lines of keyword matching were duplicated across 9 locations in `guide-payments.php`.
- **Solution**: New `products` table classifies each Bokun product by ID as `tour` or `ticket`. Filtering now happens server-side via a single `LEFT JOIN` + `WHERE` clause.

### Database Changes
- **New table**: `products` (`bokun_product_id` PK, `title`, `product_type` ENUM('tour','ticket'), timestamps)
- **New column**: `tours.product_id` INT — extracted from `bokun_data` JSON via `JSON_EXTRACT`
- **Auto-migration**: `tours.php` creates table/column on first request (guarded by `SHOW TABLES`/`SHOW COLUMNS`)
- **Backfill**: One-time population of `product_id` from existing `bokun_data` + seeding `products` table
- **Known tickets**: 7 product IDs marked as `ticket`: 809838, 845665, 877713, 961802, 1115497, 1119143, 1162586

### Backend Changes
- **tours.php**: Added `?product_type=tour|ticket|all` query parameter; `LEFT JOIN products` for filtering; default `tour` excludes tickets
- **bokun_sync.php**: Auto-registers new products via `INSERT IGNORE INTO products`; includes `product_id` in both INSERT and UPDATE
- **BokunAPI.php**: Extracts `product_id` from `productBookings[0].product.id` in `transformBookingToTour()`
- **guide-payments.php**: Replaced 9 blocks of 5-line `NOT LIKE` matching (45 lines total) with single `NOT EXISTS` subquery each

### Frontend Changes
- **Tours.jsx**: Removed client-side `filterToursOnly()` call — backend now handles filtering
- **PriorityTickets.jsx**: Passes `product_type: 'ticket'` filter to API
- **mysqlDB.js**: Added `product_type` parameter passthrough in `getTours()`

### Files Modified
- **Backend** (4 files): tours.php, bokun_sync.php, BokunAPI.php, guide-payments.php
- **Frontend** (3 files): Tours.jsx, PriorityTickets.jsx, mysqlDB.js
- **New**: `database/migrations/create_products_table.sql` (migration documentation)

### Production Verification
| Test | Result |
|------|--------|
| Tours API (default, no tickets) | 116 upcoming tours |
| Tickets API (`product_type=ticket`) | 238 upcoming tickets |
| Product type field in response | `tour` / `ticket` correctly set |
| Auto-migration on first request | Products table + backfill completed |

---

## ✅ SECURITY HARDENING (2026-02-24)

### Comprehensive Security Audit & Fixes
✅ COMPLETED - Full security review and remediation across 4 severity levels

- **Scope**: 18 vulnerabilities identified (3 Critical, 9 High, 6 Medium, 3 Low), all fixed
- **Commits**: 4 commits (`b7beeba`, `5c91244`, `b5edc8b`, `a906629`)
- **Deployed**: All changes live on production, verified via smoke tests

### Critical & High Priority Fixes
- **Deleted 57 test/debug files** from repository — contained hardcoded credentials, database info, and debug endpoints (e.g., `test_password.php`, `debug_headers.php`, `check_db.php`)
- **Added `Middleware::requireAuth($conn)`** to all API endpoints (tours, guides, payments, tickets, tour-groups, guide-payments, payment-reports, bokun_sync, bokun_webhook)
- **Fixed auth bypass** in `bokun_sync.php` — `action=sync` path skipped authentication
- **Removed wildcard CORS** `Access-Control-Allow-Origin: *` from `tours.php`
- **Enabled SSL verification** in `BokunAPI.php` — was using `CURLOPT_SSL_VERIFYPEER => false`

### Medium Priority Fixes
- **Fixed token key mismatch** — `mysqlDB.js` axios interceptor read `authToken` but `AuthContext.jsx` stored `token` in localStorage (was breaking all authenticated API calls after auth enforcement)
- **Converted SQL interpolation to prepared statements** in `guide-payments.php` — 3 queries using `real_escape_string` + string interpolation replaced with `bind_param()`
- **Suppressed error message leaks** across 10 PHP files — `$conn->error`, `$stmt->error`, `$e->getMessage()` no longer exposed to clients; moved to `error_log()` only
- **Added session cleanup** — probabilistic (5% on login) deletion of expired sessions
- **Removed info leaks** — auth default response no longer exposes database name; config error responses no longer expose environment name

### Low Priority Fixes
- **Deleted 8 remaining debug files from production server** (not in git) — including `check_getyourguide.php` which had a hardcoded password
- **Added development CSP header** — was missing entirely for dev environment
- **Fixed `.htaccess` wildcard CORS** — Apache `Header always set Access-Control-Allow-Origin "*"` was overriding PHP's environment-aware origin checking; removed CORS from `.htaccess` entirely
- **Updated `.gitignore`** — added patterns for `*_test.php`, explicit entries for `compression_test.php`, `sentry_test.php`, `tests/run_tests.php`
- **Fixed dead routes** in `index.php` — removed references to deleted files (`update_paid_status.php`, `update_cancelled_status.php`), consolidated to `tours.php`
- **Fixed `404.php`** — removed `$_SERVER['REQUEST_URI']` from JSON response

### Smoke Test Results (Production)
All 7 tests passing:
| Test | Status |
|------|--------|
| Frontend loads (200) | PASS |
| Protected endpoints return 401 | PASS |
| Login returns valid token | PASS |
| Tours API with token | PASS |
| Tickets API with token | PASS |
| Bokun sync responds correctly | PASS |
| CORS not wildcard | PASS |

### Files Modified
- **Backend** (12 files): tours.php, guides.php, payments.php, tickets.php, tour-groups.php, guide-payments.php, payment-reports.php, bokun_sync.php, bokun_webhook.php, auth.php, config.php, BokunAPI.php
- **Frontend** (1 file): mysqlDB.js (token key fix)
- **Config** (3 files): .htaccess, index.php, 404.php, .gitignore
- **Deleted**: 57 test/debug/migration files from repository + 8 from production server

---

## ✅ UNASSIGNED TOURS REPORT (2026-02-23)

### Downloadable Unassigned Tours Report
- **Purpose**: Generate a plain-text report of unassigned tours (date, time, location) for sharing with guides
- **Frontend**: New "Unassigned Report" button in the Summary card at bottom of Tours page
  - Iterates existing `groupedTours` memo (respects all active filters: date, guide, upcoming/past/date range)
  - Excludes cancelled tours and ticket products (already filtered by groupedTours)
  - Output shows only **date, time, and location** — clean format for guides
  - Location extracted from tour title via keyword matching: Uffizi, Accademia, Duomo, Pitti, Boboli, Palazzo Vecchio, San Lorenzo, Santa Croce, Ponte Vecchio, Bargello, Vasari Corridor (defaults to "Florence")
  - Downloads as `unassigned_tours_YYYYMMDD_HHmm.txt` via Blob
  - Responsive: shows "Unassigned Report" on desktop, "Report" on mobile
- **No backend changes**: Entirely client-side using existing filtered data
- **File Modified**: `src/pages/Tours.jsx`

---

## ✅ CUSTOM DATE RANGE FILTERING & CACHE FIX (2026-02-23)

### Custom Date Range Filter
- **Backend**: Added `start_date` and `end_date` query parameters to `tours.php` GET handler
  - Generates `WHERE t.date >= ? AND t.date <= ?` with prepared statements
  - Both params validated with regex `/^\d{4}-\d{2}-\d{2}$/`
  - Takes priority over `past`, `upcoming`, and `date` filters
- **Service Layer**: Added `start_date`, `end_date`, and `past` filter passthrough in `mysqlDB.js`
- **Frontend**: New "Date Range" button in Tours.jsx filter bar
  - Dual date picker with start/end inputs and "to" separator
  - `end_date` input has `min` constraint to prevent invalid ranges
  - Skips fetching while range is incomplete (only one date selected)
  - All existing filter buttons properly clear date range mode when clicked
- **Cache Fix**: Added `past` and `start_date` to `hasFilters` check in `getTours()`
  - Previously, switching to Past 40 Days or Date Range returned stale cached data from Upcoming
- **Files Modified**: `tours.php`, `mysqlDB.js`, `Tours.jsx`

---

## ✅ PDF REPORT GENERATION & PAYMENT SYSTEM FIXES (2026-01-29)

### PDF Report Generation
✅ COMPLETED - Frontend-only PDF generation using jsPDF (no PHP dependencies)

- **Purpose**: Generate professional PDF reports for guide payments directly in browser
- **Tech Stack**: jsPDF 2.5.2 + jsPDF-AutoTable 3.8.4
- **Design**: Tuscan-themed branding with terracotta accent color (#C75D3A)

- **Report Types Available**:
  | Report | Description | Columns |
  |--------|-------------|---------|
  | Guide Payment Summary | Overview of all guides with payment totals | Guide, Tours, Paid, Unpaid, Total |
  | Pending Payments | Tours awaiting guide payment | Guide, Tour, Date, Participants |
  | Payment Transactions | Detailed payment history | Guide, Tour, Amount, Method, Date |
  | Monthly Summary | Monthly payment breakdown | Month, Total Paid, Cash, Bank Transfer |

- **Files Created**:
  - `src/utils/pdfGenerator.js` - Complete PDF generation utility with 4 report templates

- **Files Modified**:
  - `src/pages/Payments.jsx` - Added PDF download buttons for Guide Payments and Pending tabs

- **Usage**:
  ```javascript
  import { generateGuidePaymentSummaryPDF, generatePendingPaymentsPDF } from '../utils/pdfGenerator';

  // Generate and download PDF
  generateGuidePaymentSummaryPDF(guidesData);
  generatePendingPaymentsPDF(pendingToursData);
  ```

- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION

---

### Payment System Critical Bug Fixes
✅ COMPLETED - Fixed three critical bugs causing inconsistent payment tracking

- **Bug 1: Table Mismatch in VIEW**
  - **Issue**: `guide_payment_summary` VIEW queried `payment_transactions` table (empty/non-existent)
  - **Impact**: All guide payment totals showed €0.00
  - **Fix**: Updated VIEW to reference `payments` table
  - **File**: `database/migrations/fix_guide_payment_summary_view.sql`

- **Bug 2: Inconsistent "Unpaid Tour" Logic**
  - **Issue**: Three different definitions of "unpaid tour" across codebase
  - **Frontend**: Used legacy `tour.paid` field
  - **Backend**: Queried `tours.payment_status` field
  - **Correct**: Check actual `payments` table for records
  - **Fix**: Added `pending_tours` API endpoint with authoritative logic

- **Bug 3: Pending Tab False Positives**
  - **Issue**: Pending tab used legacy `paid` field instead of checking `payments` table
  - **Impact**: Showed incorrect pending counts, sometimes 0 when tours existed
  - **Fix**: Changed Payments.jsx to fetch from new API endpoint

- **Files Created**:
  - `database/migrations/fix_guide_payment_summary_view.sql` - VIEW fix SQL

- **Files Modified**:
  - `public_html/api/guide-payments.php` - Added `pending_tours` action endpoint
  - `src/pages/Payments.jsx` - Changed to use API for pending tours
  - `src/components/Dashboard.jsx` - Added API call for pending payments count

- **New API Endpoint**:
  ```
  GET /api/guide-payments.php?action=pending_tours

  Response:
  {
    "success": true,
    "count": 5,
    "data": [
      {
        "id": 123,
        "title": "Tour Name",
        "date": "2026-01-15",
        "time": "09:00",
        "guide_id": 1,
        "guide_name": "Guide Name",
        "participants": 4
      }
    ]
  }
  ```

- **Verification Queries**:
  ```sql
  -- Verify VIEW uses correct table
  SHOW CREATE VIEW guide_payment_summary;
  -- Should show: LEFT JOIN payments p ON t.id = p.tour_id

  -- Test pending tours
  SELECT COUNT(*) FROM tours t
  WHERE t.date < CURDATE()
    AND t.cancelled = 0
    AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.tour_id = t.id);
  ```

- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (January 29, 2026)

---

## ✅ AUTOMATED TESTING IMPLEMENTATION (2026-01-29)

### React Testing with Vitest
✅ COMPLETED - Comprehensive testing infrastructure for frontend

- **Testing Stack**:
  - Vitest (test runner, compatible with Vite)
  - @testing-library/react (React component testing)
  - @testing-library/jest-dom (DOM assertions)
  - @testing-library/user-event (user interaction simulation)
  - jsdom (DOM environment)

- **Test Commands**:
  ```bash
  npm test           # Run tests in watch mode
  npm run test:run   # Run tests once
  npm run test:coverage  # Run with coverage report
  ```

- **Test Coverage**:
  | Component | Tests | Description |
  |-----------|-------|-------------|
  | Button.jsx | 23 | Rendering, clicks, loading, disabled, icons, accessibility |
  | Login.jsx | 16 | Form rendering, input handling, validation, structure |
  | mysqlDB.js | 13 | API calls, error handling, caching, pagination |
  | **Total** | **52** | All passing |

- **Files Created**:
  - `vite.config.js` - Updated with test configuration
  - `src/test/setup.js` - Test environment setup
  - `src/components/__tests__/Button.test.jsx`
  - `src/pages/__tests__/Login.test.jsx`
  - `src/services/__tests__/mysqlDB.test.js`
  - `public_html/api/tests/run_tests.php` - PHP API test runner

- **PHP API Tests**:
  - Simple test runner (no PHPUnit needed)
  - Tests for auth, tours, guides endpoints
  - Rate limiting verification
  - Run with: `php public_html/api/tests/run_tests.php`

---

## ✅ API RATE LIMITING IMPLEMENTATION (2026-01-29)

### Database-Backed Rate Limiting
✅ COMPLETED - Comprehensive API rate limiting for all endpoints

- **Purpose**: Protect against brute force attacks, API abuse, and spam
- **Implementation**: Database-backed storage (Hostinger-compatible, no Redis)
- **Rate Limits Configured**:
  | Endpoint Type | Limit | Window |
  |---------------|-------|--------|
  | Login/Auth | 5 requests | per minute |
  | Read operations | 100 requests | per minute |
  | Write/Create | 30 requests | per minute |
  | Update | 30 requests | per minute |
  | Delete | 10 requests | per minute |
  | Bokun Sync | 10 requests | per minute |
  | Webhooks | 30 requests | per minute |

- **Files Created**:
  - `public_html/api/RateLimiter.php` - Rate limiter class with database storage
  - `database/migrations/create_rate_limits_table.sql` - Database schema

- **Files Modified**:
  - `public_html/api/config.php` - Added rate limiting helper functions
  - `public_html/api/auth.php` - Login rate limiting (5/min)
  - `public_html/api/tours.php` - Auto rate limiting by HTTP method
  - `public_html/api/guides.php` - Auto rate limiting by HTTP method
  - `public_html/api/payments.php` - Auto rate limiting by HTTP method
  - `public_html/api/tickets.php` - Auto rate limiting by HTTP method
  - `public_html/api/guide-payments.php` - Read rate limiting (100/min)
  - `public_html/api/payment-reports.php` - Read rate limiting (100/min)
  - `public_html/api/bokun_sync.php` - Sync rate limiting (10/min)
  - `public_html/api/bokun_webhook.php` - Webhook rate limiting (30/min)

- **Features**:
  - Automatic IP detection (supports Cloudflare, proxies)
  - HTTP headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
  - 429 Too Many Requests response with Retry-After header
  - Skipped in development mode (configurable via RATE_LIMIT_DEV env var)
  - Self-cleaning: 1% chance per request to clean expired records
  - Database event scheduler for hourly cleanup (optional)

- **Deployment Required**:
  1. Run SQL migration: `database/migrations/create_rate_limits_table.sql`
  2. Deploy updated PHP files to production

---

## ✅ PRIORITY TICKETS PAGE FIX & AUTO-SYNC (2026-01-25)

### Priority Tickets API Pagination & Filter Fix
✅ COMPLETED - Fixed Priority Tickets page not displaying today's 9 bookings

- **Issue**: Priority Tickets page showing 0 tickets despite 9 bookings existing for today (2026-01-25)
  - 7 Uffizi tickets, 1 Accademia ticket, 1 Uffizi tour not visible
  - Page was fetching old 2025 records instead of new 2026 data
- **Root Cause 1**: API per_page limit capped at 100
  - `tours.php` line 95: `min(100, intval($_GET['per_page']))`
  - Database sorted by `date ASC`, so old 2025 records (IDs 1-164) returned first
  - New 2026 records (IDs 165-266) were cut off by the 100 limit
- **Root Cause 2**: PriorityTickets.jsx not using `upcoming` filter
  - Was fetching ALL records instead of future bookings only
  - Old 2025 records filled the response before 2026 data
- **Fix Applied**:
  - Increased API per_page max from 100 to 500 in `tours.php` line 95
  - Updated `PriorityTickets.jsx` to use `upcoming: true` filter
  - Now fetches only bookings from today + 60 days forward
- **Ticket Detection Enhancement**:
  - Added "Entrance Ticket" to TICKET_KEYWORDS in `tourFilters.js`
  - Now properly detects "Uffizi Gallery Priority Entrance Tickets"
- **Immediate Result**:
  - ✅ All 9 bookings for today (2026-01-25) now visible
  - ✅ 7 Uffizi tickets displayed correctly
  - ✅ 1 Accademia ticket displayed correctly
  - ✅ 1 Uffizi tour displayed correctly
- **Files Changed**:
  - MODIFIED: `public_html/api/tours.php` (line 95 - increased per_page max to 500)
  - MODIFIED: `src/pages/PriorityTickets.jsx` (line 84 - added upcoming filter)
  - MODIFIED: `src/utils/tourFilters.js` (added "Entrance Ticket" keyword)
- **Priority**: 🔴 CRITICAL - Priority Tickets page was showing no data
- **Deployment Status**: ✅ READY FOR PRODUCTION

### Automatic Bokun Sync System
✅ VERIFIED OPERATIONAL - Auto-sync infrastructure already in place and working

- **Auto-Sync Features**:
  - Syncs on app startup (if last sync > 15 minutes ago)
  - Periodic sync every 15 minutes while app is active
  - Syncs on app focus/visibility change (if > 15 minutes since last sync)
  - Non-intrusive status indicator in bottom-right corner
  - Toast notifications when new bookings are synced
- **Components**:
  - `src/components/BokunAutoSyncProvider.jsx` - Provider component with status indicator
  - `src/hooks/useBokunAutoSync.jsx` - React hook for sync state management
  - `src/services/bokunAutoSync.js` - Service class handling sync logic
- **Integration**: Already wrapped in App.jsx around all routes
- **Admin Only**: Auto-sync only runs for authenticated admin users
- **Files Verified**:
  - `src/App.jsx` - BokunAutoSyncProvider wrapping all routes
  - `src/components/BokunAutoSyncProvider.jsx` - Status indicator component
  - `src/hooks/useBokunAutoSync.jsx` - Hook with 15-minute interval
  - `src/services/bokunAutoSync.js` - Sync service with focus/visibility listeners

### Debug Files Cleanup
✅ COMPLETED - Removed temporary investigation files

- Removed: `public_html/api/today_tickets.php`
- Removed: `public_html/api/check_2026.php`
- Removed: `public_html/api/db_check.php`
- Removed: `public_html/api/bokun_debug.php`
- Removed: `verify_sync.cjs`

## ✅ PRODUCTION PAYMENTS PAGE FIX (2025-10-26)

### Payment System Database Views and Table Name Correction
✅ COMPLETED - Fixed production payments page error by creating missing database views and correcting table references

- **Issue**: Production payments page showing "Error loading payment data: Failed to load payment overview"
  - Page URL: https://withlocals.deetech.cc/payments
  - Local development page working perfectly
  - Production API returning 500 Internal Server Error
- **Root Cause 1**: Missing database views
  - `guide_payment_summary` view did not exist in production
  - `monthly_payment_summary` view did not exist in production
  - API file `guide-payments.php` line 41 required these views
- **Root Cause 2**: Table name mismatch
  - API files referenced `payment_transactions` table
  - Production database uses `payments` table (not `payment_transactions`)
  - Development and production had different table naming
- **Fix Applied**:
  - Created `guide_payment_summary` view using `payments` table
  - Created `monthly_payment_summary` view using `payments` table
  - Updated `public_html/api/guide-payments.php` - replaced all `payment_transactions` with `payments`
  - Updated `public_html/api/payments.php` - replaced all `payment_transactions` with `payments`
- **Immediate Result**:
  - ✅ Database views created successfully in production
  - ✅ API endpoint working: `/api/guide-payments.php?action=overview`
  - ✅ API endpoint working: `/api/guide-payments.php`
  - ✅ Payments page fully operational: https://withlocals.deetech.cc/payments
- **Impact**:
  - ✅ All 4 payment page tabs now functional (Overview, Guide Payments, Record Payment, Reports)
  - ✅ Payment statistics displaying correctly
  - ✅ Guide payment summaries working
  - ✅ Payment reports accessible
  - ✅ Payment recording operational
- **Files Changed**:
  - CREATED: `create_views_production.php` - Database view creation script
  - MODIFIED: `public_html/api/guide-payments.php` - Table name corrections
  - MODIFIED: `public_html/api/payments.php` - Table name corrections
  - CREATED: `PAYMENTS_PAGE_FIX.md` - Complete technical documentation
- **Production Database Changes**:
  - Created view: `guide_payment_summary` (using `payments` table)
  - Created view: `monthly_payment_summary` (using `payments` table)
- **Priority**: 🔴 CRITICAL - Payments page was completely broken
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Views deployed to: u803853690_withlocals database
  - API files updated on production server
  - Verified at: https://withlocals.deetech.cc/payments

## ✅ BOKUN SYNC DATE RANGE FIX (2025-10-26)

### Bokun Auto-Sync Optimization
✅ COMPLETED - Fixed sync to include past bookings, not just future ones

- **Issue**: Bokun sync was only fetching bookings from TODAY forward
  - Example: October 24 bookings (2 days in past) were missing from production
  - Production showed "0 of 23" while development showed "6 of 27"
- **Root Cause**: `bokun_sync.php` line 89 using `date('Y-m-d')` as start date
  - Only synced from current date forward (future bookings only)
  - Missed any bookings from yesterday or earlier
- **Fix Applied**:
  - Changed start date from TODAY to **7 DAYS AGO**: `strtotime('-7 days')`
  - Extended end date from +14 days to **+30 DAYS FORWARD**: `strtotime('+30 days')`
  - Now syncs 37-day rolling window (past 7 days + next 30 days)
- **Immediate Result**: Synced 43 bookings from Oct 19 to Nov 25
  - All 6 missing October 24 bookings imported successfully
  - Production ticket count increased from 23 to 28
- **Impact**:
  - ✅ Auto-sync now includes recent past bookings (7-day lookback)
  - ✅ Handles same-day and yesterday's bookings correctly
  - ✅ Prevents missing bookings due to sync timing
  - ✅ Better coverage for rescheduled bookings
  - ✅ No manual intervention needed going forward
- **Files Changed**:
  - MODIFIED: `public_html/api/bokun_sync.php` (Lines 87-94)
- **Priority**: 🟡 IMPORTANT - Affects booking data completeness
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Deployed to: https://withlocals.deetech.cc
  - Manual sync triggered: 43 bookings synced
  - October 24 bookings verified in production database
  - Auto-sync (every 15 minutes) now uses new date range

## 🔴 CRITICAL: TOUR DATE BUG FIX (2025-10-26)

### Priority Tickets / Tours Date Display Bug
✅ COMPLETED - Fixed critical bug where tours displayed under booking creation date instead of actual tour date

- **Issue**: Tours showing under wrong dates in Priority Tickets and Tours pages
  - Example: October 2 tour displayed under September 9 (booking creation date)
  - Caused confusion in tour scheduling and guide assignments
- **Root Cause**: `BokunAPI.php` using `booking['creationDate']` as fallback when tour date fields not found
  - creationDate = when customer MADE the booking
  - Should use startDateTime/startDate = when tour HAPPENS
- **Fix Applied**:
  - Removed incorrect `creationDate` fallback in `transformBookingToTour()` function
  - Added validation to ensure tour date exists before importing
  - Added detailed error logging for debugging
  - Throws exception for bookings without valid tour dates
- **Database Fix Script**: Created `fix_tour_dates.php` to correct existing wrong dates
  - Re-parses `bokun_data` JSON field
  - Extracts correct tour date from `startDateTime` or `startDate`
  - Updates all affected tours in database
- **Impact**:
  - ✅ Priority Tickets page now shows tours by actual tour date
  - ✅ Tour scheduling accurate for guide assignments
  - ✅ No more confusion about which tours are on which days
  - ✅ Better UX for tour guides and operations team
- **Files Changed**:
  - MODIFIED: `public_html/api/BokunAPI.php` (Lines 326-339)
  - NEW: `public_html/api/fix_tour_dates.php`
  - NEW: `TOUR_DATE_BUG_FIX.md` (Complete deployment guide)
- **Priority**: 🔴 CRITICAL - Affects tour scheduling and operations
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Deployed to: https://withlocals.deetech.cc
  - Fix script executed: 65 tours updated successfully
  - Zero errors during deployment
  - Database dates corrected from booking creation dates to actual tour dates

## ✅ REACT ROUTER 404 FIX (2025-10-26)

### Priority Tickets Direct URL 404 Error Fix
✅ COMPLETED - Fixed 404 errors when accessing routes directly or refreshing pages

- **Issue**: Direct URL access to `https://withlocals.deetech.cc/priority-tickets` returned 404 error
- **Root Cause**: Missing .htaccess file for Apache URL rewriting to support React Router SPA
- **Solution**: Created `public/.htaccess` with Apache rewrite rules
- **Fix Details**:
  - Created `public/.htaccess` with mod_rewrite rules
  - Redirects all non-file requests to `index.html` (except /api/)
  - Vite automatically copies file to `dist/` during build
  - Added security headers and caching optimization
- **Impact**:
  - ✅ All routes now accessible via direct URL
  - ✅ Browser refresh works on all pages
  - ✅ Pages can be bookmarked and shared
  - ✅ Improved SEO (all pages accessible to search engines)
- **Files Created**:
  - NEW: `public/.htaccess` - Apache rewrite configuration
  - NEW: `PRIORITY_TICKETS_404_FIX.md` - Complete deployment guide
- **Testing**: Verified with Puppeteer - direct URL access works correctly
- **Deployment**: Ready for production - see `PRIORITY_TICKETS_404_FIX.md` for instructions

## ✅ CRITICAL PRODUCTION DATABASE FIXES (2025-10-25)

### Database Schema Synchronization
✅ COMPLETED - Fixed critical column mismatches between local and production

- **Root Cause**: Production database was missing 2 columns and had 1 incorrect enum value
- **Missing Columns Added**:
  - `bokun_experience_id VARCHAR(255)` - Track Bokun experience IDs for API sync
  - `last_sync TIMESTAMP` - Track last synchronization time for Bokun integration
- **Enum Value Fixed**:
  - Modified `payment_status` enum from ('unpaid','partial','paid') to include 'overpaid' option
  - Now matches local database: ENUM('unpaid','partial','paid','overpaid')
- **Sessions Table Fixed**: Added missing `token VARCHAR(255)` column (fixed login errors)
- **Result**: Production database now has 40 columns matching local development exactly

### Priority Tickets Date Filter Fix
✅ DEPLOYED - Changed default behavior to show all ticket bookings

- **Issue**: Page defaulted to today's date, showing no data when all tickets were from past dates
- **Fix Location**: `src/pages/PriorityTickets.jsx` line 66
- **Before**: `date: new Date().toISOString().split('T')[0]` (defaulted to today)
- **After**: `date: ''` (empty = show all dates)
- **Result**: Page now displays all 50+ museum ticket bookings on load

### Error Resolution
Fixed all production application failures

- ❌ **Before**: "Unknown column 'bokun_experience_id'" errors on tour creation
- ❌ **Before**: "Unknown column 'last_sync'" errors on Bokun sync
- ❌ **Before**: "Unknown column 'token'" errors on login
- ❌ **Before**: Empty Priority Tickets page due to date filter
- ✅ **After**: All CRUD operations working correctly on production
- ✅ **After**: Authentication and session management functional
- ✅ **After**: Bokun synchronization operational
- ✅ **After**: All pages displaying data correctly

## ✅ BOOKING DETAILS MODAL & ENHANCED UX (2025-10-24)

### Booking Details Modal Component
✅ DEPLOYED TO PRODUCTION - Comprehensive modal for viewing complete booking information

- **New Component**: `src/components/BookingDetailsModal.jsx` - Reusable across Priority Tickets and Tours pages
- **6 Detailed Sections**:
  1. Tour Information (date, time, museum, duration, title)
  2. Main Contact (name, email, phone from Bokun API)
  3. Participants (adults/children breakdown, INFANT excluded)
  4. Booking Details (channel, status, confirmation codes, pricing)
  5. Special Requests (customer requirements from Bokun)
  6. Internal Notes (editable with save functionality)
- **Data Extraction**: Parses `bokun_data` JSON field and `priceCategoryBookings` array
- **Responsive Design**: 800px width on desktop, full screen on mobile
- **User Experience**: ESC key to close, click backdrop to close, body scroll locked when open

### Priority Tickets Page Major Enhancements
✅ DEPLOYED TO PRODUCTION

- **Removed Contact Column**: Moved customer contact details to modal for cleaner table view
- **Participant Breakdown**: Shows "2A / 1C" format (adults/children), INFANT tickets excluded
- **Default Today's Date**: Page automatically shows today's bookings on load
- **Morning Bookings First**: Chronological sorting (09:00, 12:00, 14:00) - earliest time first
- **Click to View Details**: Click any booking row to open comprehensive details modal
- **stopPropagation**: Prevents modal from opening during inline notes editing

### Tours Page Modal Integration
✅ DEPLOYED TO PRODUCTION

- Added same booking details modal functionality to Tours page
- Click any tour row to view complete booking information
- stopPropagation on guide assignment and notes columns
- Seamless integration with existing guide assignment workflow

### Database Schema Verification
✅ CONFIRMED ALL COLUMNS EXIST

- Verified production database has ALL required columns:
  - `language` VARCHAR(50) - For language detection ✅
  - `rescheduled` TINYINT(1) - Rescheduling flag ✅
  - `original_date` DATE - Original tour date ✅
  - `original_time` TIME - Original tour time ✅
  - `rescheduled_at` TIMESTAMP - When rescheduled ✅
  - `payment_notes` TEXT - Payment notes ✅
  - `notes` TEXT - Booking notes ✅
  - `bokun_data` TEXT - Full Bokun JSON ✅
- Production database `u803853690_withlocals` fully up-to-date
- No migration needed - all features enabled

### GitHub Integration
✅ COMPLETED

- Repository: https://github.com/DhaNu1204/guide-florence-with-locals.git
- All changes pushed to master branch
- Complete deployment documentation created

### Files Modified/Created

- NEW: `src/components/BookingDetailsModal.jsx` (409 lines)
- MODIFIED: `src/pages/PriorityTickets.jsx` (participant breakdown, modal integration, default date, sorting)
- MODIFIED: `src/pages/Tours.jsx` (modal integration, click handlers)
- NEW: `DEPLOYMENT_PLAN.md` (Complete deployment guide)
- NEW: `DEPLOYMENT_SUCCESS.md` (Deployment verification report)
- NEW: `DATABASE_SCHEMA_COMPARISON.md` (Schema comparison report)
- NEW: `MIGRATION_INSTRUCTIONS.md` (Database migration guide)

## ✅ CRITICAL BUG FIXES & UI ENHANCEMENTS (2025-10-19)

### Dashboard Chronological Sorting
✅ COMPLETED - Fixed Unassigned Tours and Upcoming Tours sorting

- **Issue**: Tours were only sorted by date, not time, causing incorrect display order
- **Fix Location**: `src/components/Dashboard.jsx` lines 86-91 (Unassigned Tours), lines 108-113 (Upcoming Tours)
- **Solution**: Implemented combined date+time sorting: `new Date(a.date + ' ' + a.time)` for accurate chronological order
- **Result**: Tours now display in true chronological sequence (e.g., 19/10 10:00, 19/10 17:00, 19/10 17:30, 21/10 09:30)

### Tours Page CRUD Operations Fix
✅ COMPLETED - Resolved guide assignment and notes persistence

- **Issue**: Guide assignments and notes were not saving to database despite correct frontend implementation
- **Root Cause**: Backend `tours.php` PUT handler (lines 307-326) missing field handling for `guide_id` and `notes`
- **Fix Applied**:
  - Added backward-compatible handling for both `guideId` (camelCase) and `guide_id` (snake_case)
  - Added complete `notes` field handling in PUT request
  - Implemented proper NULL value handling for guide assignments
- **Testing**: Verified with curl - both fields now persist correctly to database
- **Files Modified**: `public_html/api/tours.php` lines 307-326

### Priority Tickets Page Redesign
✅ COMPLETED - Enhanced museum ticket booking management

- **Removed**: Confirmation column (bokun_confirmation_code/external_id display)
- **Added**: Notes column with full inline CRUD functionality
- **Features Implemented**:
  - Click-to-edit notes interface with textarea expansion
  - Save (green checkmark) and Cancel (red X) buttons
  - Real-time database persistence using `updateTour()` API
  - Visual feedback: "Click to add notes..." placeholder for empty notes
  - Hover effects for improved user experience
- **Column Width Balancing**: Optimized table layout for better readability
  - Date: 100px, Time: 70px, Museum: 130px, Customer: 120px
  - Contact: 180px (wider for email addresses)
  - Participants: 90px, Booking Channel: 120px
  - Notes: Flexible width (expands to fill remaining space)
- **Database Integration**: Uses existing `notes` column in `tours` table (no schema changes required)
- **Files Modified**:
  - `src/pages/PriorityTickets.jsx` - Complete component update
  - Added state management: `editingNotes`, `savingChanges`
  - Added CRUD functions: `saveNotes()`, `handleNotesChange()`, `cancelNotesEdit()`
- **Icons Added**: FiSave, FiX from react-icons/fi

### Authentication Session Fix
✅ COMPLETED - Resolved login failure

- **Issue**: 500 Internal Server Error during login - sessions table INSERT missing `session_id` field
- **Fix Location**: `public_html/api/auth.php` line 56
- **Solution**: Added `session_id` field to both INSERT statement and parameter binding
- **Result**: Login now works successfully, permanent fix applied

## ✅ AUTOMATIC LANGUAGE DETECTION & PAYMENT STATUS INTELLIGENCE (2025-10-15)

### Multi-Channel Language Extraction
✅ COMPLETED - Automatic tour language detection from Bokun API

- **Method 1 (Viator)**: Extract from booking notes using regex pattern `GUIDE : English`
- **Method 2 (GetYourGuide)**: Match rateId to product rate titles (Italian Tour, Spanish Tour, etc.)
- **Method 3**: Check field locations for language indicators
- **Method 4**: Parse product title for language keywords
- Added `language VARCHAR(50)` column to tours database table
- Successfully extracted and updated 132+ tours with accurate language data
- Language badges displayed in Tours page, Dashboard (Upcoming Tours, Unassigned Tours)
- No default values - only displays actual detected language to prevent incorrect guide assignments

### Smart Payment Status Logic
✅ COMPLETED - Fixed payment tracking confusion

- Corrected distinction between customer platform payments (Bokun INVOICED) vs guide payments
- All Bokun-synced tours now correctly start as 'unpaid' for guide payment tracking
- Reset 127 existing tours from incorrect "paid" status to proper "unpaid" status
- Payment system now accurately tracks what guides need to be paid

### Ticket Product Filtering
✅ COMPLETED - Intelligent filtering of non-tour products

- Excluded "Uffizi Gallery Priority Entrance Tickets" from Tours page display
- Excluded "Skip the Line: Accademia Gallery Priority Entry Ticket" from Tours page
- Added filtering to Dashboard Unassigned Tours section
- Added filtering to Payment Record form to prevent ticket product selection
- Maintains ticket products in database for inventory management while hiding from tour workflows

### Enhanced Data Handling
✅ COMPLETED - Improved robustness

- Fixed PaymentRecordForm to handle paginated getTours() response properly
- Added safety checks for undefined ticket locations in Tickets page
- Improved error handling and null checks throughout

## ✅ CANCELLED BOOKING SYNC & RESCHEDULING SUPPORT (2025-09-30)

### Cancelled Booking Synchronization
✅ COMPLETED - Fixed sync to include cancelled bookings from Bokun API

- Enhanced Bokun API search to include 'CANCELLED' status bookings alongside 'CONFIRMED' and 'PENDING'
- Fixed frontend caching issue that prevented cancelled bookings from displaying
- Added red "Cancelled" status badges in Tours page with proper visual indicators
- Verified cancelled bookings (GET-75173181, VIA-71040572) now properly marked and displayed

### Complete Rescheduling Support
✅ COMPLETED - Full implementation for tour date/time changes

- Added database schema: `rescheduled`, `original_date`, `original_time`, `rescheduled_at` columns
- Enhanced Bokun sync logic to detect date/time changes and preserve original scheduling details
- Implemented orange "Rescheduled" status badges with hover tooltips showing original scheduling
- Prevents duplicate tour entries when clients reschedule bookings in Bokun
- Complete audit trail preservation for customer service and guide coordination

### Frontend Cache Management
✅ COMPLETED - Added refresh functionality

- Added "Refresh" button in Tours page header with spinning animation
- Force cache bypass functionality to ensure latest data display
- Fixed localStorage caching issues that showed stale booking data
- Real-time sync status with proper error handling and user feedback

## ✅ PRODUCTION DEPLOYMENT COMPLETE (2025-09-29)

- **Live Production Site**: https://withlocals.deetech.cc fully operational ✅
- **Critical Bug Fixes**: Resolved all production deployment errors including:
  - Fixed hardcoded localhost URLs in frontend components
  - Corrected API endpoint naming (.php extensions required)
  - Resolved database schema mismatches between development and production
  - Fixed missing payment system database tables
- **Bokun Integration Live**: Successfully synced 47 bookings from Bokun API on production server
- **Dashboard Functionality**: All dashboard components showing live data with proper filtering
- **Payment System Operational**: Complete payment tracking system with guide analytics working on production
- **Environment Configuration**: Proper .env.production setup with VITE_API_URL=https://withlocals.deetech.cc/api
- **Database Migration**: Successfully migrated and configured production MySQL database
- **SSH Deployment Process**: Established automated deployment via SSH (port 65002) to Hostinger hosting

## ✅ Latest Development Update (2025-09-28)

- **Application Branding Update**: Updated application name to "Florence with Locals Tour Guide Management System"
- **Enhanced User Experience**: Improved payment alerts with styled notifications replacing browser alerts
- **Editable Payment Transactions**: Added inline editing capability for payment amounts and methods
- **Layout Reorganization**: Moved user profile and logout to sidebar footer for better navigation
- **100% Mobile Responsive**: Comprehensive mobile responsiveness testing completed across all pages and components
- **UI/UX Optimization**: Sidebar displays clean "Florence with Locals" branding while maintaining full application name in titles

## ✅ Payment System Enhancement (2025-09-20)

- **Payment System Reports Enhancement**: Completed calendar-based date range filtering for payment reports
- **Italian Timezone Support**: All payment dates and reports now use proper Italian timezone (Europe/Rome)
- **Calendar Date Picker**: Advanced date range selector with quick filter buttons (Today, Last 7/30 Days, This Month)
- **Guide Payment Analytics**: Enhanced reports with guide-specific filtering and date range selection
- **Payment API Integration**: Complete CRUD operations for payment transactions with date filtering

## ✅ Bokun API Update (2025-09-13)

- **Bokun API Credentials Updated**: Corrected API keys with actual credentials from dashboard
- **2025 Date Corrections**: All date references updated from 2024 to proper 2025 dates
- **Enhanced Monitoring System**: Real-time API diagnostics with auto-refresh capabilities
- **Comprehensive Support Documentation**: Updated Bokun support reply with correct API keys and 2025 August tour examples
- **API Status Confirmed**: HTTP 303 redirects confirm authentication works but BOOKINGS_READ permission needed
- **Server Environment**: Both frontend (port 5173) and backend (port 8080) running successfully

## ✅ UI/UX Modernization Complete (2025-08-29)

- **Responsive Sidebar Navigation**: Desktop left sidebar, mobile collapsible menu
- **Modern Component System**: Card, Button, Input components with consistent styling
- **Compact Tour Cards**: Horizontal layout on desktop, single column list view
- **Mobile-First Design**: 100% responsive across all screen sizes
- **Icon Integration**: React Icons (Fi) throughout the interface
- **Color-coded Status System**: Visual indicators for tour status, payment, etc.

## ✅ Enhanced Functionality

- **Multi-Language Guide Support**: Checkbox selection for up to 3 languages per guide
- **Email Integration**: Email field added to guide registration
- **Separated Bokun Integration**: Dedicated page at `/bokun-integration`
- **Improved Error Handling**: Comprehensive error boundaries and user feedback
- **Data Validation**: Frontend and backend validation for all forms
- **Ticket Management System**: Museum entrance ticket inventory with date/time organization

## ✅ Database & API Verification

- **All CRUD Operations Tested**: Create, Read, Update, Delete for Tours, Guides, and Tickets
- **RESTful API Endpoints**: Proper HTTP methods and status codes
- **Database Integrity**: All foreign keys and relationships verified
- **Performance Optimization**: Efficient queries with proper indexing
