# Florence With Locals — Tour Management System
## Technical Analysis & Improvement Plan

**Date:** 10 September 2026
**Scope:** `D:\florence-with-locals-guide-assign-list\guide-florence-with-locals` — PHP 8 REST API (`public_html/api`, 31 files), React 18 / Vite SPA (`src`, ~47 files), deployment scripts, `.htaccess`, env files, git index, docs.
**Method:** Every source file was read in full by three parallel reviewers (backend, frontend, infra/repo); every finding listed here was then re-verified by opening the exact code path. Line numbers refer to the current files on disk. Git tracking status was read directly from `.git/index` (214 tracked files, branch `master`).

> **Not verified:** whether the dangerous scripts in §1.2 are actually present on the live server. Outbound access to `withlocals.deetech.cc` is blocked from this session. Please run the `curl` checks in §1.2 yourself — they take 30 seconds.

---

## 0. Executive summary

The system works and is in daily production use, but it has grown by accretion: the safety net (roles, encryption, rate limiting, CSP, file logging, CI) is **documented as complete while several pieces are only partially wired**. The most urgent issues are not subtle bugs — they are a handful of access-control gaps and credential leaks that a single logged-in `viewer` account, or in two cases an anonymous visitor, can exploit.

| Area | Verdict |
|---|---|
| Authorization | **Critical** — `viewer` role can do everything except P&L; Bokun secret sent to every browser |
| Secrets & PII in git | **Critical** — plaintext admin/viewer passwords, a DB password, a Bokun key, and 61 customers' emails/phones are tracked |
| Web-root exposure | **Critical (needs live check)** — unauthenticated `migrate_database.php` / `fix_tour_dates.php` are tracked, deployable, and not blocked by `.htaccess` |
| Data integrity | **High** — sync overwrites local `paid` flags every 15 min; group IDs regenerate each sync so P&L overrides orphan; time compare bug marks every booking "rescheduled" |
| Frontend correctness | **High** — cache poisoning makes Dashboard stats wrong; groups vanish in date-range view; batch payment multiplies amounts; writes report success after server rejects |
| Deployment | **High** — deploys from working tree (ships gitignored files), `rsync --delete` can wipe `.env.local`, health check always fails |
| Code health | **Medium** — 2 auto-grouping algorithms, 2 sync engines, 2 `Validator` classes, 2 `.htaccess`, 2 CORS handlers, ~60 one-off scripts at root, 100 KB page components |

Nothing here requires a rewrite. Most fixes are small and local; the ordering in §7 gets the risk down in roughly two working days.

---

## 1. CRITICAL — fix first

### 1.1 Authorization is UI-only; `viewer` role has full write access
- `Middleware::requireRole()` exists (`Middleware.php:115`) but is called **only** in `pnl.php:28`. Every other endpoint uses bare `requireAuth`: `tours.php:8`, `guides.php:6`, `payments.php:18`, `tour-groups.php:23`, `tickets.php:7`, `guide-payments.php:17`, `bokun_sync.php:1042`.
- Frontend hides admin UI (`ModernLayout.jsx:134`, `BokunIntegration.jsx:63`, `Guides.jsx:282`) and `ModernLayout.jsx:46` reads the role from `localStorage.getItem('userRole')` — editable in DevTools.
- **Impact:** a viewer (or anyone with a stolen 24 h token) can delete tours/guides, record or delete guide payments, merge/dissolve groups, trigger full syncs, and **replace the Bokun credentials** (`POST bokun_sync.php?action=config`).
- **Fix:** `Middleware::requireRole($conn,'admin')` on every POST/PUT/DELETE branch and on all of `bokun_sync.php` except `sync`/`sync-info`. Add an `AdminRoute` wrapper in `App.jsx` for `/bokun-integration` and `/daily-pnl`; derive role from `useAuth()` not localStorage.

### 1.2 Decrypted Bokun API key + secret are returned to every logged-in browser, every 15 minutes
- `bokun_sync.php:80-108` `getBokunConfig()` decrypts `api_key`/`api_secret`, copies them to `access_key`/`secret_key`, and `GET ?action=config` (`:1051-1053`) echoes the whole row.
- `src/services/bokunAutoSync.js:427-431` calls that endpoint on startup, on every window focus, and every 15 min — just to read one boolean (`sync_enabled`).
- **Impact:** the AES-256 encryption at rest is fully defeated; the secret sits in every user's Network tab.
- **Fix:** return only `configured`, `sync_enabled`, `vendor_id`, `last_sync`, and a masked key. Gate `config` GET/POST behind admin. Have `?action=sync` itself return `{success:false,error:'sync_disabled'}` so the extra round-trip disappears.

### 1.3 Unauthenticated maintenance scripts in the deployable web root
`public_html/api/.htaccess:45-46` explicitly serves any existing file (`RewriteCond -f → [L]`); there is **no deny rule at all** in the api `.htaccess`. `scripts/deploy.sh:251-258` uploads `public_html/api/*.php` from the **working tree** minus only 4 names (`sentry_test`, `migrate_bokun_credentials`, `database_check`, `bokun_debug`). These are therefore deployed unless removed by hand:

| File | Tracked in git | Auth | What an anonymous GET does |
|---|---|---|---|
| `fix_tour_dates.php` | **yes** | none, no CLI guard | Rewrites `date`/`time` of **every** Bokun tour (`:96`) using `date('H:i',$timestamp)` (UTC-derived) instead of `startTimeStr` → corrupts every tour time; echoes customer names (`:100`) |
| `migrate_database.php` | **yes** | none | Runs `ALTER TABLE` DDL on production (`:43,:86-90,:108`); the `begin_transaction` is illusory (DDL auto-commits); leaks `$e->getMessage()` (`:237`) |
| `check_environment.php` | no (but present locally) | none | Returns DB host, DB name, DB **user**, document root, environment |
| `compression_test.php` | no (present locally) | none | Leaks PHP version + Apache modules |
| `product_details.txt`, `getyourguide_data.txt` | **yes** | n/a | 100 KB Bokun product dump + GYG data served as plain text |

**Verify now (from your PC):**
```
for f in fix_tour_dates.php migrate_database.php check_environment.php compression_test.php product_details.txt getyourguide_data.txt; do curl -s -o /dev/null -w "$f %{http_code}\n" https://withlocals.deetech.cc/api/$f; done
```
Anything that returns `200` should be deleted from the server today.

**Fix:** move all `fix_*`, `migrate_*`, `check_*`, `*_test.php` to a `tools/` folder outside `public_html`; add `if (php_sapi_name()!=='cli'){http_response_code(404);exit;}` at the top of each; add to `api/.htaccess`:
```apache
<FilesMatch "^(\.env.*|.*\.(log|sql|md|json|bak|txt)|(debug|test|check|fix|migrate)_.*\.php|.*_test\.php)$">
    Require all denied
</FilesMatch>
```
and make `deploy.sh` deploy an **allowlist from `git ls-files`**, never a glob of the working tree.

### 1.4 Credentials and customer PII committed to git (verified against `.git/index`)
`.env`, `.env.local`, `.env.production` and `config.php` are **not** tracked — good. But these **are** tracked on branch `master` (and therefore on GitHub):

| File | Contents |
|---|---|
| `fix_users.php` | **Plaintext admin password and viewer password** passed to `password_hash()` (`:7,:20,:31`) |
| `server/db.js` | Hostinger DB host, user `u803853690_guideDhanu` and **plaintext DB password** (`:5-7`) |
| `database_schema_updated.sql` | bcrypt hashes for both users (`:180-181`), a viewer username that is a personal Gmail address, and a **plaintext Bokun `api_key`** + vendor id (`:200`) |
| `tours_temp.json` (563 KB) | Raw Bokun dump: **61 customer emails, 61 phone numbers, 76 customer names** — personal data under GDPR |
| `database_backup_before_payment_system.sql`, `tickets_import.csv`, `RE Your Bókun support case 00301047.txt` | DB backup, ticket data, pasted support email |
| `CLAUDE.md:89`, `docs/API_DOCUMENTATION.md:171` | Admin username + password in plain text; `CLAUDE.md` states dev and prod share it |
| `.env.example:17,77-78` | Real Bokun vendor id, real production DB name/user |

Also: `.env.local` and `.env.production` contain the **same `ENCRYPTION_KEY`** — a dev-laptop compromise equals production Bokun credentials.

**Fix (order matters):**
1. Rotate: admin + viewer passwords, Bokun API key/secret, `ENCRYPTION_KEY` (then re-encrypt `bokun_config`), the `u803853690_guideDhanu` DB password if that user still exists, Sentry DSN.
2. `git rm --cached` the files above, then purge history with `git filter-repo` and force-push; strip all `INSERT` statements from `database_schema_updated.sql`.
3. Remove credentials from `CLAUDE.md`/docs; keep them in an untracked local file.
4. Add `gitleaks` as a pre-commit hook / CI job.

### 1.5 Login rate limiting is bypassable
- `RateLimiter.php:258-284` trusts `X-Forwarded-For`, `X-Real-IP`, `Client-IP` from any client. On Hostinger nothing sets these, so an attacker gets a fresh 5/min bucket per random header value.
- The second limiter in `auth.php:50-54` silently disables itself if `login_attempts` is missing (`if (num_rows===0) return true`) — the table's CREATE exists only as a loose SQL file, not self-provisioned like the others.
- **Fix:** use `REMOTE_ADDR` only (or a `TRUSTED_PROXIES` allowlist); create `login_attempts` in code like `rate_limits`; also key the login limiter on username.

---

## 2. HIGH — data integrity & security

### 2.1 Bokun sync overwrites local payment state every 15 minutes
`bokun_sync.php:339-360` UPDATE sets `total_amount_paid`, `expected_amount`, `payment_status`, `paid` from Bokun on **every** sync; `BokunAPI.php:582-585` sets `'paid' => $totalAmount > 0 ? 1 : 0`. Consequences: every priced booking shows a green "Paid" badge (`Tours.jsx:1523`, `TourCardMobile.jsx:241`, `Dashboard.jsx:242`) that reads as "guide paid"; any `paid`/`expected_amount` you set via `tours.php` PUT is reverted within 15 min; `expected_amount` in Pending Payments is the customer's retail price, not the guide fee.
**Fix:** exclude those four columns from the sync UPDATE (set on INSERT only); store Bokun's price in a dedicated `bokun_total_price` column.

### 2.2 Group IDs change on every sync → P&L overrides, notes, max_pax are orphaned
`autoGroupAfterSync()` (`bokun_sync.php:786-799, 872-885`) detaches all auto-group tours in range, deletes orphans, and "always creates a fresh group" with a new id. `pnl.php:46` keys overrides on `tour_unit = 'g<group_id>'`; `tour_groups.notes`/`display_name`/`max_pax` live on the group row. Every manual P&L cost/revenue override on a grouped departure silently reverts ≤15 min later.
**Fix:** make auto-grouping incremental (reuse a group whose member set matches; only touch changed buckets), or key overrides on a stable natural key (`product_id|date|time`).

### 2.3 Every synced booking is flagged "rescheduled" on every sync
`bokun_sync.php:327` compares `$existing['time']` (MySQL `TIME` → `10:00:00`) with `$tourData['time']` (`startTimeStr` → `10:00`) with `!==` → always true → `rescheduled=1`, `rescheduled_at=NOW()` rewritten on every row every sync. The UI badge only looks right because `Tours.jsx:1533` re-derives it from `original_*` columns.
**Fix:** `substr($existing['time'],0,5) !== substr($tourData['time'],0,5)`.

### 2.4 Empty booking window = "sync failed" → groups on that date never rebuilt
`BokunAPI.php:252-303`: zero bookings from both roles falls through to two legacy endpoints and `throw $lastError`; `syncBookings` logs `failed` and skips `autoGroupAfterSync`. A quiet day or a webhook for a date whose only booking moved away leaves stale groups.
**Fix:** return `[]` on a successful empty search; fall back only on exception.

### 2.5 Bokun webhook is unauthenticated and triggers N full syncs
`bokun_webhook.php:108-154`: no signature/shared secret; every distinct date in the payload triggers `syncBookings()` (2 roles × pages, advisory lock, Twilio reconcile) with no cap; payload stored verbatim, unbounded. One POST with 500 dates = 500 sync passes.
**Fix:** require `?key=` compared with `hash_equals`, cap dates per event (≤3), cap stored payload size.

### 2.6 N+1 Bokun API calls inside sync (likely cause of the 145–267 s syncs)
`BokunAPI.php:490-530`: each booking with no language in notes calls `GET /activity.json/{productId}` (30 s timeout) with no per-product cache. 1,000 bookings / 20 products = up to 1,000 calls; the 400/min quota then makes `makeRequest` `sleep()` up to 60 s inside a web request.
**Fix:** static `$productCache[$productId]` per run; prefer `productBookings[0].rateTitle` first.

### 2.7 Encryption format is ambiguous — ~1.7 % of ciphertexts are treated as plaintext
`Encryption.php:269` heuristic `/^[a-zA-Z0-9]{8,}$/` ⇒ "looks like a plain key". Base64 output that happens to contain no `+ / =` is returned raw by `ensureDecrypted()` → Bokun HMAC auth fails until credentials are re-saved (measured: ~1.7 % of encryptions of a 32-char secret). Plus `saveBokunConfig()` (`bokun_sync.php:126-138`) **stores plaintext** if the key is missing, with only an `error_log`.
**Fix:** prefix ciphertext `enc:v1:`; make `isEncrypted()` test the prefix only; fail hard when the key is missing; derive separate enc/mac keys via `hash_hkdf` or switch to `aes-256-gcm`.

### 2.8 Frontend: `getTours()` cache is poisoned by filtered queries → Dashboard stats wrong
`mysqlDB.js:264` builds `cacheKeyWithFilters` and never uses it; `:332-343` writes every response to the single key `tours_v1`; `:268` `hasFilters` ignores `product_type`/`page`. `Dashboard.jsx:108-110` fetches `{upcoming:true}` then `{}` 50 ms later — the second call gets the **upcoming** list from cache, so `paidTours`/`totalGuidedTours` never include past tours. `PriorityTickets.jsx:109` (`product_type:'ticket'`) can leave **tickets** in the cache for the next unfiltered caller.
**Fix:** drop the localStorage response cache (or key it by the full query). Better: TanStack Query.

### 2.9 Frontend: tour groups silently render as ungrouped bookings in date-range view
`Tours.jsx:356-367` sends `start_date/end_date/past`, no `per_page`, and swallows errors (`.catch(() => ({data: []}))`). `tour-groups.php:147-171` only understands `date`, `guide_id`, `upcoming` and defaults to 50/page → in Date Range mode you get the oldest 50 groups in the table; members of missing groups render as standalone rows (`Tours.jsx:536-549`), losing PAX/FULL badges and 1-group-1-payment semantics.
**Fix:** add `start_date/end_date` to `listGroups`, request `per_page=100` and page through, surface the error.

### 2.10 Frontend: batch "Record Payment" posts the same amount to every selected tour
`Payments.jsx:205-221` loops `selectedTours` and sends `amount` each time; the label says just "Amount (€)" next to "N tours selected". Entering the total for 3 tours records 3× that total. The mixed-guide override (`:184-186`) also records all selected tours against one guide, bypassing the per-guide duplicate check.
**Fix:** label "Amount per tour" + live "Total: €X"; split the batch by each tour's own `guide_id`.

### 2.11 Frontend: writes report success after the server rejected them
`mysqlDB.js:539-551` (`updateTour`), `:377-410` (`addTour`, fabricates `local-<ts>` ids), `:429-445` (`deleteTour`); `ticketsService.js:175-193, 223-236 (returns true on failure), 285-307`. Any non-409 error → local cache updated → "Guide assigned successfully!" toast while the DB is unchanged.
**Fix:** delete every localStorage fallback branch and rethrow.

### 2.12 Deployment
- `deploy.sh:283-289` `rsync --delete` excludes only `.htaccess`, `api/`, `.env` — **`.env.local`** (EnvLoader's top-priority file, `EnvLoader.php:33`) and `logs/` are deleted on every frontend deploy.
- `deploy.sh:126,135` health check requires HTTP 200 from auth-protected endpoints → always 401 → every deploy ends "with warnings", exit 1. (`deploy.yml:169` already accepts 401; `deploy.sh` never got the fix.)
- No dirty-tree check, no `npm ci` unless `node_modules` missing, no SHA recorded. `deploy.sh:258` exclusion uses unanchored `grep -q` (regex, substring).
- `.github/workflows/deploy.yml` runs `npm ci` at repo root (no `working-directory`) — can't work in the monorepo layout even if SSH were reachable; references a non-existent `database_check.php`.
- `config.php:113` environment detection **defaults to `development`** (display_errors on, DEBUG on, rate limiting off) if the host path/name ever changes. Default must be production with explicit `APP_ENV=development` opt-in.
- `config.php:144` sets `log_errors=1` but no `error_log` path → on Hostinger errors go nowhere (CLAUDE.md admits "debug via DB side-effects"). Set `ini_set('error_log', <path outside web root>)`.
- Server `.env` search paths (`EnvLoader.php:32-37`) are all **inside web-served directories**, two of them inside the main `deetech.cc` docroot whose `.htaccess` this repo does not control. Move it outside the web root.

---

## 3. MEDIUM — correctness & consistency

**Backend**
- Two live auto-grouping algorithms: `tour-groups.php:324-496` (title-normalised, hard-coded max 9, includes private tours) vs `bokun_sync.php:747-952` (product_id, `getMaxPaxForTitle()` → 19 for Accademia, excludes private). The Tours-page button creates groupings the next sync tears down; `manualMergeTours` (`:542`) rejects legitimate Accademia merges > 9 PAX. Keep one.
- `guide-payments.php` still uses `title NOT LIKE '%Entry Ticket%'` keyword filtering in 7 places (`:167-171, 188-192, 279-283, 517-521, 539-543, 560-564, 626-630`); only `getAllGuidePaymentSummaries` uses the `products` table → summary and pending counts disagree for tickets without those keywords (e.g. Borghese).
- `payments.php:237,407` and `tours.php:399,624`: `"abc" <= 0` is false in PHP 8, so a non-numeric amount binds as `0.0` and a €0 payment marks the unit paid. Use `is_numeric() && (float) > 0`.
- No logout endpoint; tokens valid 24 h with no revocation; stored plaintext in `sessions.token`. `AuthContext.jsx:88` only clears localStorage. Add `DELETE auth.php`, hash tokens.
- `tours.php:14-160` runs 9 `SHOW COLUMNS` + an `INSERT … ON DUPLICATE KEY` on **every request**; `RateLimiter.php:58` runs `CREATE TABLE IF NOT EXISTS` on every request; similar in `tour-groups.php:29`, `guide-requests.php:29-49`, `twilio_reminders.php:316`, `pnl.php:530`. Move to versioned migrations.
- Uncaught `mysqli_sql_exception` (PHP ≥ 8.1 strict mode) in `tours.php`, `guides.php`, `tickets.php`, `tour-groups.php` → blank 500s; `SentryLogger.php:544` re-throws; every `E_WARNING` triggers a synchronous 5 s Sentry cURL (`tours.php:424-425` reads undefined indexes → 2 Sentry round-trips per manual tour creation).
- `bokun_sync.php` mutating actions (`sync`, `backfill-names`, `config` write path) are GET; `start_date`/`end_date` unvalidated → uncaught exception from `logSyncOperation` (line 275, outside the try), and a huge range detaches every auto-group in the DB.
- `tours.php:738` delete leaves `tour_groups.total_pax` stale and payments with `tour_id=NULL` that vanish from the list but still count in overview totals. `guide-requests.php:200-250` accept is not atomic (two guides can both "win") and doesn't propagate to the group.
- Timezone split: PHP `Europe/Rome`, MySQL session tz never set; `RateLimiter` writes `window_start` with PHP `date()` but cleans up with `NOW()`. Run `SET time_zone='+00:00'` after connect and use UTC consistently.
- `bokun_sync.php:284` logs the full raw API response (multi-MB) per sync; `BokunAPI.php:75-76` logs request headers **including `X-Bokun-AccessKey`**.
- `RateLimiter.php:83-93` read-then-increment is not atomic; use `INSERT … ON DUPLICATE KEY UPDATE`.
- `payment-reports.php:313-322` export path skips `validateDate()` and echoes input into `Content-Disposition`.
- `Middleware.php:284` and `Validator.php:9` both declare `class Validator` — any file requiring both fatals.

**Frontend**
- `index.html:16-40` global `!important` grid rules (`.grid-cols-1 {…!important}`, `.md\:grid-cols-2`) beat every Tailwind responsive variant (verified: `tailwind.config.js` has no `important:` setting). `Payments.jsx:612,1155,1608` (`md:grid-cols-4`) and `DailyPnL.jsx:353` stay 1-column on desktop; `md:grid-cols-2` beats `lg:grid-cols-3/4` in `Payments.jsx:703,1439`. The `#tours-list table` rule targets an id that no longer exists. Delete the block.
- Two sync engines run in parallel (`bokunAutoSync.js:347-372` + `useBokunAutoSync.jsx:70-101`); `performSync` never throws so `lastSync=now` is written after failures → next sync skipped 15 min and "Last sync: Just now" shown on failure; focus/visibility listeners registered at import time are never removed on logout.
- Date handling: `new Date('YYYY-MM-DD')` (UTC midnight) then local formatting in `Tours.jsx:1236,29-47`, `Dashboard.jsx:153-235,323,519,588`, `Payments.jsx:53,810,814,1093,1116,1289`, `Tickets.jsx:314-337`, `PriorityTickets.jsx:224` → a day early for any user west of UTC. `new Date(date+' '+time)` in `Dashboard.jsx:161-199`, `PriorityTickets.jsx:118-119`, `dateFormatting.js:234` is `Invalid Date` on Safari/iOS → dashboard lists unsorted on the iPhone PWA. One `parseYmd()` helper, everywhere.
- `Dashboard.jsx:150-228` counts per booking not per departure (a 3-booking unassigned group = 3 alerts) and re-applies keyword ticket filtering (`:142-143`, `PriorityTickets.jsx:114`) on server-classified data — a tour titled "Skip-the-Line Uffizi with Expert Guide" is misclassified as a ticket and hidden from the needs-a-guide alert.
- `ticketsService.js` reads `tickets_v1` but `clearCache()` removes `tickets_cache` → deleted tickets reappear on refresh within 60 s.
- `ModernLayout.jsx:52-57` implements its own logout without `useAuth().logout()` → `isAuthenticated` stays true, Back re-enters protected pages, sync keeps running; `:45` reads `username` but AuthContext writes `userName` → header always shows "User". `AuthContext.jsx:289-297` treats a network error during `verify` as an invalid token → opening the PWA offline logs you out.
- `Tours.jsx:1139-1145` unmounts the whole page on `loading` — the 15-min background sync replaces the page while you're typing a note or have a select open; no request sequencing in `loadData` (`:344-391`) so a slow earlier response can overwrite a newer filter's result.
- State arrays sorted in place during render: `Payments.jsx:810,1093,1265`, `Tickets.jsx:348-362`.
- `BokunMonitor.jsx:14` polls a non-existent `bokun_diagnostics.php` every 30 s without a token (mounted at `BokunIntegration.jsx:276`).
- `EditTour.jsx:149-159` calls `toursData.find` on a `{data,pagination}` object → always "Failed to load tour data"; nothing links to the route.
- `Payments.jsx:323-327,527-544`: a failed Reports fetch blanks all five tabs.
- `tours.php:264` `SELECT t.*` ships full `bokun_data` JSON for 500 rows, stored twice in localStorage (`tours_v1` + legacy `tours`); quota errors swallowed.
- Sentry: `tracesSampleRate: 1.0` + Replay + `sendDefaultPii: true` in prod (`main.jsx:15`); `App.jsx:190` `showDialog` pops Sentry's feedback dialog on any crash.
- `AskGuideModal.jsx:48` strips non-digits, so a `0039…` number becomes `wa.me/0039…` which WhatsApp rejects.

**Infra / `.htaccess`**
- Two divergent root `.htaccess` (`public/` vs `public_html/`), neither shipped by `deploy.sh`; `public_html/.htaccess` has no `<IfModule>` guards (missing `mod_headers` = HTTP 500) and no caching/PWA rules; `public/.htaccess:32` `FilesMatch "\.(env|log|sql)$"` does **not** match `.env.local`.
- No CSP on the actual SPA document — `config.php:257` sets CSP only on JSON API responses (where it does nothing), and it contains `'unsafe-inline' 'unsafe-eval'`. Security headers are duplicated (htaccess `Header always` + PHP `header()`).
- `api/.htaccess:54-55` answers OPTIONS with a bare 200 before PHP → no CORS headers on preflight for pretty URLs (latent, same-origin today).
- Hashed `/assets/*` cached 1 month via `Expires` only; should be `Cache-Control: public, max-age=31536000, immutable`; `index.html` needs `no-cache`.
- gzip applied twice (mod_deflate + `ob_gzhandler`).
- `bokun_cron.php` docblock tells cron to write its log **inside `api/`**.

---

## 4. LOW / hygiene

- **Dead code shipped to production:** `BaseAPI.php`, `Logger.php` (so "structured file logging with rotation" in the README is inert — nothing calls it), `Validator.php`, `HttpClient.php`, `Middleware::handleCORS()`, `recalculateGroupPax()`, `checkAuth()`, `mapBookingStatus()`, `autoAssignGuide()` (references a non-existent `guide_availability` table), `tours.php` `/paid` `/cancelled` sub-routes (last-segment parsing → always 400), `mysqlDB.js:454-513`, `services/bokunService.js` (imports Node `crypto`), `localStorageDB.js`, `utils/dateFormatting.js`, `utils/bokunDataExtractors.js`, `EditTour.jsx`, `BokunMonitor.jsx`, `backend/` (old Express server), `server/db.js`, root `api/tickets.php`, two stale `public_html` build copies (one with a **relative** asset base that breaks `/respond/:token`).
- `database_schema_updated.sql` is stale (no `sessions.token`, no views, no `tour_groups`, no `login_attempts`; `payment_status` enum lacks `overpaid` which `tours.php:404` accepts).
- `EnvLoader.php:118-124` casts numeric-looking secrets (`DB_PASS=0123` → `123`); `:91-94` writes every env key into `$_SERVER` (an env key named `HTTP_HOST` would override request data). `config.php:168-177` re-parses `.env.local` with a second cruder parser.
- `config.php:230-243` emits `Access-Control-Allow-Origin` + `Allow-Credentials: true` even for disallowed origins; plain `http://withlocals.deetech.cc` is an allowed credentialed origin.
- Response shapes are inconsistent (`{data,pagination}` vs `{success,data}` vs bare arrays vs `{success:false}` with HTTP 200) — the frontend special-cases each.
- Non-sargable `CONCAT(t.date,' ',t.time) < ?` throughout `guide-payments.php` blocks the `tours(date)` index. Suggested indexes: `payments(tour_id,guide_id)`, `tours(product_id,date,time)`, unique `sessions(token)`.
- `guides.php:14,91,190` `error_log`s full request bodies (guide phone/email). `tickets.php` parses the id from the raw `REQUEST_URI` including the query string; all its errors are HTTP 200.
- `twilio_reminders.php:522-541`: nothing sets `status='sent'`, so every fired reminder is later "cancelled" against Twilio; rejected numbers retried every ~15 min for 7 days.
- `tests/run_tests.php` expects 200 from unauthenticated `/tours.php` → fails against the current API.
- Frontend tests: 10 files are tracked (Button, DateFilter, Dashboard collapse/smoke, Login, Tours/GuideReports/GuideRespond smoke, mysqlDB, tourCapacity). None cover the bugs above (cache keying, group pagination, batch payment amount, date parsing). CLAUDE.md says 91 tests, README says 52.
- No `React.lazy` anywhere; jsPDF, react-datepicker, Sentry Replay all in the main 1.47 MB chunk. Page components: `Payments.jsx` 105 KB, `Tours.jsx` 84 KB, `Tickets.jsx` 68 KB, `DailyPnL.jsx` 46 KB.
- `@tailwindcss/postcss ^4` installed alongside `tailwindcss ^3.4` — one is unused. `package.json` license `ISC` on proprietary code.
- Docs: two READMEs disagree on table/test counts and Node version; `docs/PROJECT_STATUS.md` names a different production DB; `IMPROVEMENT_TASKS.md:96` still says "push so GitHub Actions deploys" though auto-deploy is disabled; ~15 `BOKUN_*.md`/`*_FIX.md` and a 56 KB hand-written `DEPLOY_LOG.md` at root.
- Monorepo nesting (`florence-with-locals-guide-assign-list/guide-florence-with-locals/`) buys nothing; the root holds only a README, a CI file, a stale build and the Claude plugin.

---

## 5. What is good (keep it)

Prepared statements are used consistently — no SQL injection found in any endpoint. Bokun HMAC signing is correct. Advisory locks + transactions around grouping are the right pattern. The service worker is correctly network-first for navigations and bypasses `/api/`. Encryption is encrypt-then-MAC with `hash_equals` (just the format/key-derivation issues above). The group-aware payment model and 409-with-override is sound. Mobile components and the products-table classification are a real improvement over the old keyword approach — they just haven't been rolled out everywhere yet.

---

## 6. Architectural recommendations (after the fixes)

1. **Front controller + route table** (`api/index.php`): bootstrap, rate limit → auth → role per route, one try/catch → uniform `{success,data|error}` JSON. Removes the per-file drift that caused §1.1 and §3.
2. **Separate "Bokun facts" from "local operational state"** in `tours`: explicit column list the sync may overwrite; `guide_id`, guide-payment fields, notes are never touched by sync (§2.1).
3. **Stable departure identity**: incremental group reconciliation so `group_id` survives syncs; key P&L overrides by natural key (§2.2). Long term, have the API return *departures with embedded bookings* so Tours, Dashboard, Payments and P&L count the same thing.
4. **Sync off the request path**: one server-side worker (webhook + external cron ping, global `GET_LOCK`), product cache, Twilio reconcile as a separate step; browsers only read status.
5. **Versioned migrations** (`database/migrations/NNN_*.sql` + `schema_migrations` table + `tools/migrate.php`) replacing per-request `SHOW COLUMNS`.
6. **Frontend data layer**: TanStack Query (keyed by full URL, dedupes Dashboard's double fetch, `isFetching` without unmounting, invalidation on mutation) — replaces the hand-rolled cache and all "fallback" code. `React.lazy` per route, dynamic `import('jspdf')`.
7. **One of everything**: one auto-grouper, one sync engine, one `.htaccess`, one CORS handler, one `Validator`, one README, one env parser.
8. **Money as integer cents** (or `DECIMAL` + `bcmath`) end-to-end.
9. **Repo layout**: flatten to a single root with `api/`, `src/`, `public/`, `database/migrations/`, `tools/` (never deployed, CLI-guarded), `docs/` (+ `docs/history/` for the old fix logs), `.env.example` as the only env file, `gitleaks` in CI, allowlist-based `deploy.sh` with a dirty-tree check and a new unauthenticated `health.php`.

---

## 7. Suggested order of work

| Step | Effort | Items |
|---|---|---|
| **Day 1 (security)** | ~4 h | Rotate credentials (§1.4) · delete/verify web-root scripts on the live server (§1.3) · `requireRole` on all write endpoints + `bokun_sync.php` (§1.1) · stop returning Bokun secret (§1.2) · `REMOTE_ADDR`-only rate limiting + provision `login_attempts` (§1.5) · webhook secret (§2.5) |
| **Day 1–2 (git)** | ~2 h | `git rm --cached` + `filter-repo` for PII/creds files · strip INSERTs from schema · scrub CLAUDE.md/docs · add gitleaks |
| **Day 2 (deploy)** | ~3 h | Allowlist deploy from `git ls-files`, dirty-tree check, always `npm ci`, ship both `.htaccess`, drop `--delete` or exclude `.env*`/`logs/`, `health.php`, `config.php` default = production, `error_log` path, move server `.env` outside web root |
| **Week 1 (data integrity)** | ~1–2 d | Sync column list (§2.1) · time compare (§2.3) · empty-window (§2.4) · product cache (§2.6) · encryption prefix + fail-closed (§2.7) · one auto-grouper + `getMaxPaxForTitle` in manual merge · finish products-table filtering in `guide-payments.php` · amount validation |
| **Week 1 (frontend)** | ~1–2 d | Remove localStorage cache/fallbacks (§2.8, §2.11) · groups date-range + pagination (§2.9) · batch payment amount semantics (§2.10) · delete `index.html` `<style>` block · one sync engine + `destroy()` on logout · `parseYmd()` helper · logout via AuthContext + server logout |
| **Week 2+ (structure)** | ongoing | Stable group ids / P&L keys (§2.2) · versioned migrations · front controller · TanStack Query + code splitting · split the 4 giant pages · delete dead code · flatten repo |

---

*Every finding above was verified against the source on disk on 10 Sep 2026. Findings marked "needs live check" could not be confirmed against the production server from this session.*
