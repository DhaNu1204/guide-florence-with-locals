# Florence with Locals — Tour Management System

> Read this file first, every session. It is short on purpose. Deep detail lives in `docs/`.
> The owner (Dhanu) does not use the terminal: Cowork-Claude writes prompts, **you (Claude Code) execute all git/terminal work** and report back in plain language.

## 1. What this is
Internal web app for a Florence tour operator: Bokun bookings (Viator, GetYourGuide, direct) are synced into MySQL, grouped into departures, assigned to guides, paid, and reported (guide reports, Daily P&L, museum tickets, WhatsApp guide reminders).
- Production: `https://withlocals.deetech.cc` · Staging: `https://stagingwithlocals.deetech.cc` (Phase 0.1)
- Stack: React 18 + Vite 5 + Tailwind 3 (SPA/PWA) · PHP 8.2 REST API (no framework, mysqli) · MySQL · Hostinger shared hosting · Bokun REST (HMAC-SHA1) · Twilio WhatsApp · Sentry
- Layout: `src/` (frontend) · `public_html/api/` (API) · `database/migrations/` · `tools/` (CLI-only scripts, **never deployed**) · `scripts/deploy.sh` · `docs/`

## 2. The working rule — ONE STEP AT A TIME
All work follows `docs/IMPLEMENTATION_PLAN.md` (phases → numbered steps, each with a Verify checklist and rollback). Findings behind the steps are in `TECHNICAL_ANALYSIS_2026-09-10.md`.

Use the skills (they encode the loop; don't improvise around them):
- `/implement-step <id>` — branch `step/<id>` from `master` → change only what the step says → `php -l`, `npm run test:run`, `npm run build` → deploy **staging** → smoke test + step checklist → merge to `master` → deploy **production** → smoke test → tick the step, one line in `docs/CHANGELOG.md`.
- `/staging-deploy staging|production` — deploy + the 12-row smoke test. Production only from `master`, only after the same commit passed on staging.
- `/bug-triage` — reproduce on staging with SQL + curl **before** touching code; output a triage note and a plan step id.

Hard rules:
1. **Deploy branch is `master`.** `origin/main` is an old divergent branch — never merge or deploy it.
2. **Never deploy a dirty tree, and never from a `step/*` branch.** Deploy ships an allowlist from `git ls-files`, not the working tree.
3. **Never touch payment logic unless the step says so**; never change passwords; never print or commit secrets (tokens, keys, DSNs, phone numbers of guides).
4. **Do not refactor, rename, reformat or "improve" code outside the step.** Notice something? Write it as a note in the plan, not in the code.
5. **Verify with observed values**, not "should work": counts, HTTP codes, payload sizes, `sync_logs` rows. Report them.
6. A red check stops the step. Fix on the branch or roll back — never "proceed anyway".
7. Hostinger cron does not fire and PHP `error_log` is not persisted on the host: debug via DB side-effects (`sync_logs`, `bokun_webhook_logs`, `tours.last_sync`) — until step 2.1 gives `error_log` a file.
8. Ports: frontend 5173, backend 8080 (`php -S localhost:8080 -t public_html`). Local DB `florence_guides`.

## 3. Credentials & environment
- **No credentials in this repo, ever.** Admin/staging logins, SSH host/port/user, DB names and API keys live in the untracked file `~/.florence/credentials.md` on the owner's PC (ask the owner if it is missing). Server secrets live only in the server `.env` (outside the web root after step 2.2).
- Secret scanning (step 0.3): `gitleaks` runs in CI (`.github/workflows/main.yml`) and as a pre-commit hook - install it once per clone: `cp scripts/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit`.
- `.env.example` is the only env file in git. `EnvLoader.php` reads the server env; `config.php` picks the environment from `APP_ENV` (production by default after step 2.1).
- Bokun credentials are stored AES-encrypted in `bokun_config` (`Encryption.php`); they must never be returned by any endpoint (step 1.2).

## 4. Architecture in one screen
- **Request flow:** Browser → `Authorization: Bearer <token>` → `config.php` (DB, CORS, headers) → `Middleware::requireAuth($conn)` (+ `requireRole($conn,'admin')` for writes, step 1.1) → `autoRateLimit()` → endpoint → JSON.
- **Frontend data:** `src/services/mysqlDB.js` (axios + Bearer interceptor; localStorage cache is being removed in Phase 4/5 — do not add to it). Components using raw `fetch` must use `authFetch()`. Public page `/respond/:token` uses plain `fetch` on purpose (no token).
- **Sync:** `bokun_sync.php` → `BokunAPI.php` (SUPPLIER + SELLER roles, paginate by page fullness, 60-day default window) → upsert `tours` (`UNIQUE uniq_tours_external_id`, `INSERT … ON DUPLICATE KEY UPDATE`) → `autoGroupAfterSync()` → `reconcileGuideReminders()` (exception-isolated). Triggers: in-app 15-min timer, Bokun webhook (`bokun_webhook.php`, body-driven, per-date), manual (admin). Define `BOKUN_SYNC_LIB` before requiring `bokun_sync.php` to use it as a library.
- **Departure = tour unit:** `IF(t.group_id IS NOT NULL, CONCAT('g',t.group_id), CONCAT('t',t.id)) AS tour_unit` — payments, P&L, reports and reminders all count per unit, never per booking.
- **Classification:** `tour_classification.php` is the single source of truth for private products/rates and per-product max PAX (Uffizi 9, Accademia 19). Tour vs ticket comes from the `products` table (`product_type`), not title keywords.
- Routes / providers / endpoints: `docs/ARCHITECTURE.md`, `docs/API_DOCUMENTATION.md`.

## 5. Business rules you must not break
Full detail with the reasoning in **`docs/DOMAIN_RULES.md`** — read the section for the area you touch. The short list:
- Auto-groups = same `product_id | date | HH:MM`; cancelled, private and `product_id IS NULL` tours are never auto-grouped; manual merges (`is_manual_merge=1`) are never touched by auto-grouping; a guide set on a group propagates to every member tour.
- Cancelled bookings are excluded from every count, PAX total, badge, payment and P&L figure.
- 1 group = 1 payment; duplicate payment → HTTP 409 unless `force_group_payment` / `force_payment`.
- "Field omitted = don't touch; field present but null = SET NULL" — use `array_key_exists`, not `isset`, in dynamic UPDATE builders (guide unassign depends on it).
- Guide reminders: one WhatsApp per departure, ~60 min before start, Europe/Rome; WANTED decides retention, CREATABLE (≥ now+15 min) gates creation; failures are swallowed and retried on the next reconcile.
- Guide phones must be international (`+`/`00` + 8–15 digits); the backend never guesses a country code.
- Time from Bokun = `startTimeStr` (local), never the UTC timestamp. `rateId` = `productBookings[0].fields.rateId`.
- P&L: unit overrides live in `pnl_tour_costs`; guide cost precedence outsourced > ticket > private rate > mixed (highest) > category rate; Uffizi ≥ 16:00 uses PM ticket rates.
- Vite `base` must stay `'/'` (deep routes like `/respond/:token` break with `'./'`).

## 6. Conventions
- PHP: prepared statements only; generic error messages to the client, details to `error_log`; every new endpoint starts with `require_once 'config.php'; require_once 'Middleware.php'; Middleware::requireAuth($conn);` and `requireRole` for writes; response envelope `{success, data}` / `{success:false, error}` with a real HTTP status.
- New tables/columns: a numbered file in `database/migrations/` **and** (until Phase 8) the existing `CREATE TABLE IF NOT EXISTS` self-provision pattern.
- React: functional components + hooks; Tailwind Tuscan theme (terracotta `#C75D3A`, cream `#FAF6F0`); mobile-first, 44px touch targets; dates via the shared `parseYmd/formatYmd` helpers (step 5.6) — never `new Date('YYYY-MM-DD')`.
- Tests: Vitest + RTL in `__tests__/`; add one when you touch `src/utils`, `src/services` or logic-bearing components. `npm run test:run` must be green before any deploy.
- Commits: `step <id>: <what>` with the verified values in the body. One step per PR/merge.

## 7. Where to look
| Need | File |
|---|---|
| The plan and current step | `docs/IMPLEMENTATION_PLAN.md` |
| Why a step exists | `TECHNICAL_ANALYSIS_2026-09-10.md` |
| Business rules, Bokun/Twilio/P&L internals | `docs/DOMAIN_RULES.md` |
| API reference | `docs/API_DOCUMENTATION.md` |
| Recent changes | `docs/CHANGELOG.md` |
| Old CLAUDE.md (history) | `docs/history/CLAUDE.md-2026-07-17.md` |
| Troubleshooting | `docs/TROUBLESHOOTING.md` |
