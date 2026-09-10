---
name: staging-deploy
description: Deploy the current build to staging or production on Hostinger and run the mandatory smoke test against that target. Use for "deploy to staging", "push to production", "run the smoke test".
---

# Deploy + smoke test

`$ARGUMENTS` = `staging` (default) or `production`.

## Guards
- `production` only from branch `master` (never `main`) with a clean tree, and only after the same commit passed on staging (check `docs/CHANGELOG.md` or ask). Otherwise refuse.
- `git status --porcelain` must be empty for either target.

## Deploy
```
scripts/deploy.sh --target <target>
```
The script must: `npm ci` → `npm run build` with the target's `VITE_API_URL` → upload an allowlist from `git ls-files public_html/api` (never `tools/`, never `*_test.php`, never `fix_*`/`migrate_*`/`check_*`) plus `public_html/api/.htaccess` and `dist/` (with `dist/.htaccess`) → keep the previous release in `backups/` → `curl` `api/health.php` and expect 200 with the deployed git SHA.

## Smoke test (all must pass; print a table of results)
Host: `https://staging.withlocals.deetech.cc` or `https://withlocals.deetech.cc`. Tokens come from `STAGING_ADMIN_TOKEN` / `STAGING_VIEWER_TOKEN` env vars (ask the user to log in and paste, or obtain via `POST /api/auth.php` with credentials from the untracked `~/.florence/credentials.md` — never from the repo).

| # | Check | Expect |
|---|---|---|
| 1 | `GET /api/health.php` | 200, `sha` = `git rev-parse HEAD` |
| 2 | `GET /` | 200, HTML contains `/assets/index-` |
| 3 | `GET /api/tours.php?upcoming=true&per_page=1` no token | 401 |
| 4 | same with admin token | 200, JSON `data` array |
| 5 | `GET /api/tour-groups.php?upcoming=true` admin | 200 |
| 6 | `DELETE /api/tours.php/999999` with **viewer** token | 403 (not 401, not 404) |
| 7 | `POST /api/payments.php` `{}` with viewer token | 403 |
| 8 | `GET /api/bokun_sync.php?action=config` admin | 200 and body contains **no** `secret_key` / `api_secret` |
| 9 | `GET /api/migrate_database.php`, `fix_tour_dates.php`, `check_environment.php` | 404 or 403 each |
| 10 | `GET /.env.local` and `/api/.env` | 403/404 |
| 11 | `GET /api/bokun_sync.php?action=sync-info` admin | 200, last status not `failed` |
| 12 | Response headers on `/` | exactly one `Strict-Transport-Security`, one `X-Frame-Options`, a `Content-Security-Policy` |

Checks 6–8 will fail until Phase 1 is complete — before that, mark them "expected-fail (pre-1.x)" instead of blocking, but say so loudly.

## Manual part (ask the user to do on the phone for production)
Open the PWA on mobile data → Tours list shows today's departures grouped, with PAX badges.

## On failure
Print the failing rows, run `scripts/deploy.sh --target <target> --restore-last-backup`, re-run check 1–2, and stop. Do not retry a deploy in a loop.
