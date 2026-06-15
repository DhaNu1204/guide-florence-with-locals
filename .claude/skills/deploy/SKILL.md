---
name: deploy
description: Safe, verified production deploy for the Florence with Locals app. Use whenever changes must ship to https://withlocals.deetech.cc — i.e. when the user says deploy, push to production, ship it, go live, or commit and push. The live deploy path is running `bash scripts/deploy.sh` (frontend+backend, with remote backup). Verifies files are complete, blocks payment/password changes, runs the deploy script, confirms the live bundle, and logs the deploy.
---

# Deploy — Florence with Locals

The working deploy method is running **`bash scripts/deploy.sh`** from the project root. It tests SSH, makes a remote backup, builds the frontend (vite), scp's the backend PHP files, and rsync/scp's the frontend. Follow every step. Goal: zero broken deploys.

## Hard rules
1. Work on branch `master`. Never `main` (abandoned, unrelated history).
2. Never modify payment logic (payments.php, Payments.jsx, payment calc/grouping) or passwords/secrets/.env*. If a diff touches these, STOP and ask the user.
3. Verify each changed file is COMPLETE (not truncated) before committing.
4. Log every deploy in DEPLOY_LOG.md.

## Steps
1. `git branch --show-current` (must be master) ; `git status` ; `git --no-pager diff --stat`
2. Review full diff: `git --no-pager diff`. Confirm intended changes only, and NO payment/password changes.
3. Verify completeness: `for f in $(git diff --name-only); do echo "== $f =="; tail -3 "$f"; done` — PHP must end with proper `}`/`?>`, JS/JSX with a complete statement. If anything is cut off, STOP.
4. Lint changed PHP: `for f in $(git diff --name-only -- 'public_html/api/*.php'); do php -l "$f"; done` — all must say "No syntax errors detected".
5. Stage intended files, then `git commit -m "<clear message>"` (keep commits local; do NOT push — see "GitHub Actions" below).
6. **Run the deploy:** `bash scripts/deploy.sh` from the project root. Let it run to completion.
7. Confirm the deploy succeeded by these lines appearing in the script output:
   - `SSH connection OK`
   - `Backup created: ...`
   - `Backend deployment complete`
   - `Frontend deployment complete`
   If it stops early with `Cannot connect to server. Check your SSH configuration.`, SSH key access isn't set up on this machine — STOP and report that exact message.
8. Verify the new frontend bundle is actually live:
   `curl -s --ssl-no-revoke https://withlocals.deetech.cc/ | grep -o 'assets/[^"]*\.js' | head -3`
   The hashed filename(s) must match the freshly built `dist/assets/*.js` from this run.
9. Append a row to DEPLOY_LOG.md: `| date time | short-sha (deploy.sh) | master | summary | manual deploy |`
10. Report what shipped, the deployment-complete lines, the live asset filename, and the health-check result.

## Health checks — what success looks like
- **Frontend `/` must return 200.** This is the primary success signal, together with the script's `Backend deployment complete` / `Frontend deployment complete` lines.
- **Authenticated endpoints return 401, not 200.** `tours.php`, `guides.php`, and `auth.php` now enforce `Middleware::requireAuth`, so an unauthenticated curl correctly returns **401** — that means the endpoint is alive and auth is enforced. A 200 there would mean auth is NOT enforced. Do not treat 401 as a failure.
- **On this Windows machine, curl needs `--ssl-no-revoke`** or it returns `000` (schannel `CRYPT_E_NO_REVOCATION_CHECK` — it can't reach the CA revocation servers). The deploy script's built-in health checks do NOT pass this flag, so they may report `000` / "Some health checks failed!" even on a successful deploy. Re-check manually with `--ssl-no-revoke` before concluding anything failed.

## GitHub Actions (future option — currently DISABLED)
- `.github/workflows/deploy.yml` exists but auto-deploy is **NOT active**: **Hostinger blocks SSH (port 65002) from GitHub runner IPs** — the secrets (`SSH_HOST`, `SSH_PORT`, `SSH_USERNAME`, `SSH_PRIVATE_KEY`) and a dedicated deploy key ARE configured and work locally, but the runner can't reach the server. A push-triggered run fails at the "Deploy Backend (PHP files)" step with `ssh: connect to host ... port ...: Connection timed out`.
- The workflow is therefore `workflow_dispatch`-only (no `push:` trigger). **Use `scripts/deploy.sh` for deploys** — it works from this machine. To enable auto-deploy later, convert the workflow to FTPS upload or use a self-hosted/static-IP runner Hostinger allows, then restore a `push:` trigger.

## Reference
Repo: https://github.com/DhaNu1204/guide-florence-with-locals | Branch: master | Deploy script: scripts/deploy.sh | Workflow (disabled): .github/workflows/deploy.yml | URL: https://withlocals.deetech.cc | Prod path: /home/u803853690/domains/deetech.cc/public_html/withlocals
Server-side sync relies on the Bokun webhook (api/bokun_webhook.php) + in-app 15-min sync. Hostinger cron does NOT work — don't depend on it. api/bokun_cron.php is a CLI-only inert fallback.
