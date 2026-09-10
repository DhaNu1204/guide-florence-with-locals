# Florence With Locals — Tour Management System
## Implementation Plan (one step at a time, each verified)

**Created:** 10 September 2026 · **Companion doc:** `TECHNICAL_ANALYSIS_2026-09-10.md` (findings referenced as §x.y)
**Decisions taken:** staging environment first · security before features · radios = 1 receiver per person + 1 transmitter per guide, every departure · GYG missing tours: to be checked (likely products not connected to Bokun)

### How every step is executed (the rule)
Each step below is small enough to finish and verify in one sitting. For every step, Claude Code (or you) follows the same loop — this is what the `.claude/skills/implement-step` skill enforces:

1. `git checkout -b step/<id>` from `master` (the deploy branch — `origin/main` is an old divergent branch, never use it); tree must be clean.
2. Make **only** the change described in the step. No drive-by refactors.
3. Verify locally: `php -l` on touched PHP · `npm run test:run` · `npm run build` · the step's own **Verify** checklist.
4. Deploy to **staging** (`scripts/deploy.sh --target staging`) and run the **Smoke test** (below) plus the step's checklist against staging.
5. Commit with the step id in the message, merge to `master`, deploy to production, re-run the smoke test on production.
6. Tick the step in this file (`[x]`) and append one line to `docs/CHANGELOG.md`. If anything fails at 4 or 5, roll back (each step lists how) and do **not** proceed to the next step.

### Smoke test (run after every deploy, staging and production — 3 minutes)
- [ ] Login as admin → Tours page loads today's tours with groups shown as groups (PAX badges visible)
- [ ] Assign a guide to one tour, refresh → still assigned; unassign → cleared
- [ ] Guides page opens; Payments → Pending tab opens; Daily P&L opens (admin)
- [ ] Bokun Integration → "Sync now" completes (status `success`, not `failed`)
- [ ] Login as **viewer** → Guides/Payments show no edit controls; `curl -X DELETE …/api/tours.php/1 -H "Authorization: Bearer <viewer token>"` → **403**
- [ ] `curl -I https://<host>/api/health.php` → 200; `curl -I https://<host>/api/migrate_database.php` → 404/403
- [ ] Open the PWA on the phone on mobile data → Tours list appears

---

## Phase 0 — Safety net (do first, ~1 day)

### 0.1 Staging environment on Hostinger — [x] DONE 2026-09-10 (commit 1be84c6, merged to master; production not redeployed — goes live with 0.2)
- Create subdomain `stagingwithlocals.deetech.cc` → `…/public_html/stagingwithlocals/`; create DB `u803853690_withlocals_stg` from a **fresh dump of production** (with a `UPDATE users SET password=…` for a staging-only admin password); copy the server `.env` with `APP_ENV=staging`, staging DB creds, **`BOKUN_SYNC_ENABLED=false`** by default (so staging never writes reminders/Twilio to real guides — add a `TWILIO_DRY_RUN=true` flag honoured in `twilio_reminders.php`).
- `scripts/deploy.sh --target staging|production` (target selects path + `VITE_API_URL`); production is refused unless `--target production` is explicit.
- `config.php`: environment from `APP_ENV` (`EnvLoader`), **default = production** (§2.12); CORS list includes the staging origin.
- **Verify:** staging login works; sync on staging with `BOKUN_SYNC_ENABLED=true` reads from Bokun fine; Twilio dry-run logs but does not send. **Rollback:** none needed — production untouched.

**Notes from 0.1 (observed, not changed — for later steps):**
- Real staging hostname is `stagingwithlocals.deetech.cc` (hPanel created it without the dot); docroot `public_html/stagingwithlocals/`; DB + user `u803853690_withlocals_stg` (Hostinger prefix — `~/.florence/credentials.md` lists them without it). No Hostinger MCP server is configured on the owner's PC; DNS/subdomain/DB were created in hPanel by hand.
- **Staging admin password is still the production one**: the "Staging admin login" section of the credentials file was a placeholder, so the `UPDATE users SET password=…` of this step was skipped. Fill it in, then run that one UPDATE on the staging DB (do not reuse production passwords on staging).
- `config.php` was gitignored (`.gitignore:64`) although 0.1/2.1 change it and 0.2 deploys from `git ls-files`; it is now tracked (it holds no secrets).
- The plan assumed a `BOKUN_SYNC_ENABLED` env flag; the only existing flag was `bokun_config.sync_enabled` in the DB (staging inherits `1` from the dump). The env gate was added to `bokun_sync.php` (`bokunSyncEnabledByEnv()`, reported by `sync-info`).
- `deploy.sh` still ships the working tree (6 untracked `check_*`/`*_test`/`migrate_*` files went to staging), does not ship `.htaccess` (root + api — copied from production by hand on staging), and its health check treats the 401 from `tours.php`/`guides.php` as failure (exit 1 on every deploy) → all 0.2.
- **Production finding (untouched):** every browser-triggered sync (`triggered_by` = `periodic`/`startup`/`visibility`, full 67-day window) dies at the 60 s web timeout and leaves `sync_logs.status='started'` forever — 158 such rows in 24 h vs 275 completed. Only `cron` (240–330 s) and `webhook` complete, and the cron fires **twice** per 15-min slot (two rows at the same second → duplicate crontab entry). CLAUDE.md rule 7 ("Hostinger cron does not fire") is wrong. Proposed step for Phase 3: browser sync uses a short window (today → +7 d) or is dropped in favour of cron + webhook; dedupe the cron; mark `started` rows older than 10 min as `failed`.
- `public_html/withlocals/api/error_log` (10 MB) **is** written on the host and contains the Bokun access key in logged request headers (`BokunAPI: Headers:` lines) → add to the 0.3 rotation list and to 2.1 (never log auth headers).
- Staging `sync_logs` row 13855 is a stuck `started` row from the same full-window attempt on staging (harmless).

### 0.2 Repo hygiene that blocks nothing
- Add `api/health.php` (no auth: `{ok:true, db:true, env, sha}`), used by deploy checks (§2.12).
- Add `tools/` (outside `public_html`) and **move** `fix_tour_dates.php`, `migrate_database.php`, `migrate_bokun_credentials.php`, `check_environment.php`, `compression_test.php`, `sentry_test.php` there with a CLI guard at the top of each. Delete them from the live server (`ssh … rm`) — verify with `curl` → 404.
- Add the `FilesMatch` deny block to `public_html/api/.htaccess` (§1.3) and make `deploy.sh` ship `public_html/api/.htaccess` and `dist/.htaccess`.
- `deploy.sh`: deploy from `git ls-files` allowlist, refuse dirty tree, always `npm ci`, replace `rsync --delete` with an explicit include set (never delete `.env*`, `logs/`), health check accepts 200 from `health.php`.
- **Verify:** smoke test on staging then production; `curl` the six removed scripts → 404. **Rollback:** `deploy.sh` keeps the previous release in `backups/`; restore that folder.

### 0.3 Purge secrets & PII from git (§1.4)
- `git rm --cached` + `git filter-repo --invert-paths` for: `fix_users.php`, `server/db.js`, `backend/`, `tours_temp.json`, `database_backup_before_payment_system.sql`, `tickets_import.csv`, `RE Your Bókun support case 00301047.txt`, `product_details.txt`, `getyourguide_data.txt`, `add_username_column.php`, `update_dhanu_email.php`, both stale `public_html` builds. Strip all `INSERT` rows from `database_schema_updated.sql`. Remove passwords from `CLAUDE.md` / `docs/API_DOCUMENTATION.md` / `docs/ENVIRONMENT_SETUP.md` / `docs/IMPROVEMENTS_SUMMARY.md` / `DEPLOYMENT_CHECKLIST.md` / `DEPLOYMENT_PLAN.md` / `DEPLOYMENT_SUCCESS.md` / `FINAL_DEPLOYMENT_INSTRUCTIONS.md` / `HOSTINGER_DEPLOYMENT_GUIDE.md` / `public_html/api/create_users_table.sql` (all still contain the old admin password; grep `***REMOVED***` and `password_hash('` must return nothing afterwards). Force-push; re-clone.
- **Rotate**: admin + viewer passwords, Bokun key/secret, `ENCRYPTION_KEY` (re-encrypt `bokun_config` with `tools/migrate_bokun_credentials.php`), old DB user password, Sentry DSN. Use a different `ENCRYPTION_KEY` for dev.
- Add `gitleaks` pre-commit hook + CI job.
- **Verify:** `git log --all -- tours_temp.json` empty; `gitleaks detect` clean; login works with new passwords; sync works with new Bokun creds.

---

## Phase 1 — Critical security (~1 day)

| Step | Change | Verify | Rollback |
|---|---|---|---|
| **1.1** Server-side roles (§1.1) | `Middleware::requireRole($conn,'admin')` on every POST/PUT/DELETE in `tours.php`, `guides.php`, `tickets.php`, `tour-groups.php`, `payments.php`, and on all of `bokun_sync.php` except `sync`, `sync-info`, `unassigned`. Add `AdminRoute` in `App.jsx` for `/bokun-integration`, `/daily-pnl`; `ModernLayout` takes role from `useAuth()`. | Viewer token: DELETE tour → 403, POST payment → 403, GET tours → 200. Admin: all writes still work. Existing vitest suite green. | Revert commit. |
| **1.2** Stop leaking the Bokun secret (§1.2) | `action=config` returns `{configured, sync_enabled, vendor_id, last_sync, api_key_masked}` only; `bokunAutoSync.js` stops calling `config` — `action=sync` returns `{success:false, error:'sync_disabled'}` when disabled. | Network tab on staging shows no `secret_key`/`api_secret` anywhere; auto-sync still runs every 15 min; disabling sync in settings stops it. | Revert. |
| **1.3** Rate limiter (§1.5) | `getClientIp()` → `REMOTE_ADDR` only (opt-in `TRUSTED_PROXIES` env); self-provision `login_attempts` like `rate_limits`; login limiter keyed on IP **and** username. | 6 wrong logins in a minute → 429 even with random `X-Forwarded-For` headers; `SHOW TABLES LIKE 'login_attempts'` exists on staging. | Revert. |
| **1.4** Webhook secret + caps (§2.5) | `bokun_webhook.php` requires `?key=<WEBHOOK_SECRET>` (`hash_equals`); max 3 dates per event; payload stored truncated to 64 KB. Update the URL in Bokun. | POST without key → 401; with key → 200 and sync log row; a payload with 50 dates → only 3 processed. | Revert + restore old URL in Bokun. |
| **1.5** Logout + hashed tokens (§3) | `auth.php?action=logout` deletes the session; `AuthContext.logout()` calls it; `ModernLayout` uses `useAuth().logout()`; store `sha256(token)` in `sessions.token` (migration: hash existing rows). Fix `username`/`userName` key so the header shows the name. | Logout → old token gets 401; header shows the user's name; Back button after logout lands on /login. | Revert; sessions table migration is reversible (`ALTER` not needed — column stays). |
| **1.6** Encryption fail-closed (§2.7) | Ciphertext prefix `enc:v1:`; `isEncrypted()` checks prefix only; `saveBokunConfig()` returns 500 if key missing; re-encrypt existing rows via `tools/`. | Save Bokun config on staging 200× in a loop → 0 decrypt failures (was ~1.7 %); remove `ENCRYPTION_KEY` on staging → save returns 500, never plaintext. | Keep old decrypt path for un-prefixed values during transition. |

---

## Phase 2 — Config & deploy hardening (~half day)

- **2.1** `config.php`: `error_log` path outside web root; remove second `.env.local` parser; drop `Access-Control-Allow-Origin` for disallowed origins; drop plain-http origin; `SET time_zone='+00:00'` after connect. **Verify:** errors appear in the log file; CORS preflight from staging origin works.
- **2.2** Server `.env` moved outside the web root; `EnvLoader` reads `FWL_ENV_FILE` first. **Verify:** `curl https://<host>/.env.local` → 403/404; app still works.
- **2.3** One `.htaccess` (from `public/`, plus HTTPS redirect + HSTS); real CSP on HTML only; `immutable` caching for `/assets/`, `no-cache` for `index.html`; remove duplicate headers and `ob_gzhandler` from PHP. **Verify:** `curl -I` shows exactly one of each header; Lighthouse "uses efficient cache policy" passes; app loads after a deploy without a hard refresh.
- **2.4** Kill the two dead-weight workflows: keep `main.yml` as CI (scoped `php -l public_html/api`, `npm run test:run`, `npm run build`, gitleaks); delete `deploy.yml` or convert to FTPS with an allowlist. **Verify:** CI green on a PR.

---

## Phase 3 — Data integrity & the "unassigned" bug (~2 days)

- **3.1 Sync must not overwrite local state (§2.1).** Remove `paid`, `payment_status`, `total_amount_paid`, `expected_amount` from the sync UPDATE; add `bokun_total_price DECIMAL(10,2)` and write Bokun's amount there; badge in Tours/mobile card reads "Customer paid" from `bokun_total_price`, "Guide paid" from `payments`. **Verify:** set `paid=0` on a tour, run sync → still 0; P&L revenue unchanged (it reads `bokun_data`).
- **3.2 Time compare (§2.3).** `substr(...,0,5)` on both sides; `rescheduled_at` only when changed. **Verify:** run sync twice → `SELECT COUNT(*) FROM tours WHERE rescheduled_at > NOW()-INTERVAL 5 MINUTE` is 0 the second time.
- **3.3 Empty window ≠ failure (§2.4).** **Verify:** sync a date with no bookings → `sync_logs.status='success'`, groups on that date rebuilt.
- **3.4 Product cache in sync (§2.6).** Static per-run cache + prefer `rateTitle`. **Verify:** `sync_logs.duration` for a full sync drops (record before/after); Bokun request count per sync logged.
- **3.5 The unassigned-list bug.** Two verified causes and one probable:
  1. When `autoGroupAfterSync` recreates a group it copies the guide from the first member onto the **group** (`bokun_sync.php:905-922`) but never calls `propagateGuideToTours()` — so a booking that joins an already-assigned departure keeps `tours.guide_id = NULL` / `needs_guide_assignment = 1`. Anything that looks at tours individually (Dashboard "needs guide", `?action=unassigned`, guide filter, pending payments, the report when the group row is missing) shows it as unassigned. **Fix:** call `propagateGuideToTours($conn,$newGroupId,$guideId)` after setting the group guide, and make `propagateGuideToTours` a shared helper included by `bokun_sync.php`.
  2. The report (`Tours.jsx:1035-1135`) is built from the page's loaded state; when the group list is missing/paginated (§2.9) members render as standalone rows with their own (NULL) `guide_id` → false "unassigned". **Fix:** add `start_date/end_date` + full pagination to `tour-groups.php`; better, move the report server-side: `GET tours.php?action=unassigned-report&start=&end=` returning departures (groups **or** single tours) where the *effective* guide (`COALESCE(tg.guide_id, t.guide_id)`) is NULL. This same endpoint becomes an AI-assistant tool later (Phase 7).
  3. Probable: the localStorage cache (§2.8) serving a stale list from before an assignment. Fixed by 5.1.
  **Verify:** create a booking on an assigned departure on staging (or simulate via SQL), run sync → the new tour has `guide_id` set; the report for today matches a manual count; a tour assigned 1 minute ago never appears.
- **3.6 One auto-grouper (§3).** `tour-groups.php` "Auto-group" button calls `autoGroupAfterSync()`; `manualMergeTours` uses `getMaxPaxForTitle()`. **Verify:** merging two Accademia bookings totalling 12 PAX succeeds; Uffizi 10 PAX still refused.
- **3.7 Stable group identity (§2.2).** Incremental regroup: for each computed bucket, if an existing auto-group has the identical member set, keep it; else update members; only create when new. **Verify:** group ids on a date are identical before/after a sync with no changes; P&L overrides survive a sync.
- **3.8 Finish products-table filtering in `guide-payments.php`; amount validation `is_numeric && > 0`.** **Verify:** Borghese ticket no longer appears in Pending Payments; POST payment with `amount:"abc"` → 400.
- **3.9 Sync mutations to POST + date validation; delete legacy `autoAssignGuide`, `BaseAPI`, `Logger`, `Validator` duplicate, `HttpClient`.** **Verify:** php -l, smoke test.

---

## Phase 4 — Mobile speed & low-signal use (~2–3 days) — *your #1 pain point*

Why it is slow today (measured from the build and the code): the main JS chunk is **1.47 MB** (jsPDF, datepicker, Sentry Replay all eager, no route splitting); the Tours page requests **500 rows with the full `bokun_data` JSON** for each (multi-MB on a busy month) and the Dashboard fetches that twice; the app cannot show anything until those requests finish, and the service worker deliberately never caches `/api/`. On a weak signal every open is a cold start.

- **4.1 Code-split.** `React.lazy` per route; dynamic `import('jspdf')` inside the PDF handlers; Sentry Replay sampling 0 in prod / `tracesSampleRate 0.1`; remove `showDialog`. **Verify:** `npm run build` main chunk < 400 KB gzip < 130 KB; Lighthouse mobile performance on staging before/after (record both numbers).
- **4.2 Light list endpoint.** `tours.php?view=list` returns only the columns the list needs (no `bokun_data`, no notes) plus embedded group summary; `BookingDetailsModal` fetches `tours.php/{id}` on open. Enable `mod_deflate`/`gzip` verified on JSON. **Verify:** payload for a 300-booking week drops from MB to < 150 KB; modal still shows all sections.
- **4.3 Offline-first data.** Replace the localStorage cache with TanStack Query + `persistQueryClient` (IndexedDB): the last successful Tours/Dashboard/Guides data renders **instantly** with a "Updated 12 min ago — refreshing…" banner, then refreshes in the background; `staleTime` 60 s; mutations invalidate. Service worker: keep app-shell precache; API stays network-only (the query cache handles offline). **Verify:** phone in airplane mode → open PWA → today's tours visible with the banner; back online → banner updates; assign a guide with bad signal → optimistic update, rolled back with a toast if the request fails.
- **4.4 Stop unmounting on background sync.** Tours page keeps the list mounted with a small "syncing" indicator; request sequencing with `AbortController`. **Verify:** typing a note while the 15-min sync fires keeps focus and text.
- **4.5 Precache + install prompt.** Vite PWA plugin (`vite-plugin-pwa`, `generateSW`) instead of the hand-written `sw.js`; precache hashed assets so the second open is fully offline. **Verify:** Chrome DevTools "Offline" → app shell loads.
- **4.6 Meeting-point mode (small, high value).** A `/today` route: today's departures, time-sorted, guide, PAX, meeting point, radios needed (Phase 6.3), one-tap call/WhatsApp guide — a single ~10 KB API call, cached offline, shown as the PWA start URL on mobile. **Verify:** loads in < 1 s on 3G throttling with a warm cache; works offline.

---

## Phase 5 — Frontend correctness (~2 days)

- **5.1** Delete localStorage cache + all "fallback success" branches in `mysqlDB.js` / `ticketsService.js` (§2.8, §2.11) — covered by 4.3's query layer. **Verify:** stop the API on staging → every write shows an error toast, nothing "succeeds".
- **5.2** Groups in date-range view + pagination (§2.9). **Verify:** date range covering 3 weeks shows all groups grouped.
- **5.3** Batch payment semantics (§2.10): "Amount per tour" + live total; split by each tour's guide. **Verify:** record 3 tours at €50 → three €50 rows, total €150 shown before submit.
- **5.4** Remove the `!important` block from `index.html`. **Verify:** Payments overview shows 4 cards in a row on desktop, 1 column on phone.
- **5.5** One sync engine with `destroy()` on logout; `lastSync` only on success. **Verify:** failed sync shows "Last sync failed at …", retries on next focus.
- **5.6** `parseYmd()`/`formatYmd()` helpers replacing every `new Date('YYYY-MM-DD')` and `new Date(date+' '+time)`; ESLint `no-restricted-syntax` rule. **Verify:** vitest with `TZ=America/New_York npm run test:run` — dates unchanged; dashboard sorted on iPhone Safari.
- **5.7** Dashboard counts per departure, drops keyword ticket filtering (§3). **Verify:** Dashboard "needs guide" equals Tours summary "need guide".
- **5.8** Delete dead frontend code (`EditTour`, `BokunMonitor`, `bokunService`, `localStorageDB`, `dateFormatting`, `bokunDataExtractors`, paid/cancelled helpers). **Verify:** build green, no route 404s.

---

## Phase 6 — Features (~1 week)

### 6.1 Language filter
- Backend: `tours.php` and `tour-groups.php` accept `language=` (exact match on `tours.language`; group language = `GROUP_CONCAT(DISTINCT language)`); `GET tours.php?action=languages` returns distinct values for the current range. Backfill `tours.language` from `bokun_data` for rows where it is NULL (the extraction logic in `Tours.jsx:220-273` moves into `BokunAPI.php` so it runs once at sync time, not per render).
- Frontend: language dropdown next to the guide filter; chip on each card; included in the unassigned report and `/today`.
- **Verify:** filter "Spanish" shows only Spanish departures; a mixed group shows "EN/ES"; count matches SQL.

### 6.2 Merged-tour cost in Daily P&L
- Today each P&L unit is `g<group_id>` or `t<tour_id>` (`pnl.php:46`). Add a `pnl_unit_links` table (`date, unit_a, unit_b, created_by`) keyed by **natural keys** (`product_id|date|time` — depends on 3.7) so a link survives syncs. When two units on the same day are linked, `pnlBuildRows()` emits **one** combined row: revenue = sum, ticket cost = sum, **guide cost = one guide at the highest applicable rate**, radios = combined PAX + 1 transmitter, gelato = combined. Unlink restores two rows.
- UI: in Daily P&L, select two rows on the same day → "Merge for costing" → combined row with a "merged" badge and an unlink button.
- **Verify:** two 4-PAX Uffizi tours at 09:00 and 09:15 merged → one guide cost, 8 radios + 1 transmitter, revenue = both; day total drops by one guide fee; still merged after a sync.

### 6.3 Radio planner & radio account
- Rule (your answer): every departure needs **PAX receivers + 1 transmitter per guide**; a merged unit (6.2) counts once with combined PAX.
- `GET radios.php?date=` → per time slot (15-min buckets, configurable) and per departure: receivers, transmitters, cumulative "in use" curve (assuming a configurable tour duration per product, default from the product), and the **daily order line**: peak concurrent receivers/transmitters + safety margin (setting). Same data feeds `/today` (4.6) and the AI assistant.
- Radio **account**: `radio_orders` (date, supplier, receivers_ordered, transmitters_ordered, unit cost, returned, lost, notes) with a small page under Daily P&L; P&L `radio_cost` can then use actual order cost instead of `radio_per_person × people` when an order exists for the day.
- Optional: WhatsApp/email the order line to the radio supplier from the page (Twilio path already exists).
- **Verify:** a day with 3 departures (5, 8, 9 PAX at 09:00/09:15/14:00, 2 h each) → 09:15 slot shows 13 receivers / 2 transmitters in use, 14:00 shows 9/1; order line = 13 + margin; entering an order updates the day's P&L radio cost.

### 6.4 Missing GetYourGuide tours
- First check (you): are these products connected to Bokun as a GYG channel product? If **yes**, sync already handles them (SELLER + SUPPLIER roles) — then the likely culprit is classification (`products.product_type`) or a title keyword; one booking reference will show which.
- If **no** (sold directly on GYG): add a **manual booking form** (`tours.php` POST already exists; add a proper UI with product, date, time, PAX, names, language, channel = "GYG direct", `external_id = GYG-<ref>` for de-dup) and, as a second step, a **GYG email parser**: forward GYG booking emails to a mailbox polled by `tools/import_gyg_mail.php` (IMAP) that creates the same rows. GYG's Supplier API is a third option if you have partner access.
- **Verify:** a manually added GYG booking appears in Tours, groups with a same-slot Bokun booking, is counted in radios/P&L, and is not duplicated by a later sync.

---

## Phase 7 — AI assistant (spec to be refined with you; ~1 week)

Architecture that is safe on shared hosting and keeps data in your control:
- **Backend** `api/assistant.php` (admin-only, rate-limited): receives the chat, calls the Claude API server-side (key in `.env`, never in the browser) with **tool use**. Tools are thin, read-only PHP functions over the existing queries — the same ones the UI uses, so answers always match the screens:
  `unassigned_departures(start,end)`, `guide_availability(date|range)` (from `availability_requests` + assigned tours), `guide_schedule(guide, range)`, `revenue(range, product?, channel?)` and `pnl(range)` (from `pnl.php`), `radios_needed(date)`, `tour_lookup(booking ref | customer name)`, `bookings_by_language(range)`. Later, write tools with confirmation (assign guide, send reminder).
- Output: text answer plus optional table/CSV/PDF (reuse `pdfGenerator`), and a "show in app" link that opens the filtered page.
- **Frontend**: chat drawer available on every page (and on `/today`), voice input on mobile (Web Speech API), history per user.
- Guardrails: tools only (no free SQL), per-day token budget, all calls logged (`assistant_logs`), PII minimised in prompts (names only when asked).
- **Verify:** "unassigned tours this weekend" returns the same list as the report; "how much did we earn from Uffizi tours in August" matches P&L; "how many radios tomorrow at 9" matches the planner.

---

## Phase 8 — Structure (ongoing, after the above)
Versioned migrations + `schema_migrations` (§3) · front controller with route table and uniform JSON envelope · split `Payments.jsx` / `Tours.jsx` / `Tickets.jsx` into feature folders · money as integer cents · flatten the repo (drop the nested folder, `public_html/api` → `api/`) · single README + `docs/history/`.

---

## Claude Code operating notes
- `CLAUDE.md` must be rewritten: remove credentials (0.3), put the **step loop** and **smoke test** at the top, link to this plan and the analysis, and cut the changelog-style sections into `docs/CHANGELOG.md` (the file is 58 KB and mostly history).
- Skills added to the repo under `.claude/skills/`: `implement-step` (the loop above), `staging-deploy` (deploy + smoke test against a target), `bug-triage` (reproduce on staging with SQL/curl before touching code). Use them by name: `/implement-step 3.5`.
- Never run `deploy.sh --target production` from a step branch; only from `master` after staging passed.
