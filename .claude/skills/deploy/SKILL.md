---
name: deploy
description: Safe, verified production deploy for the Florence with Locals app. Use whenever changes must ship to https://withlocals.deetech.cc — i.e. when the user says deploy, push to production, ship it, go live, or commit and push. Verifies files are complete, blocks payment/password changes, pushes to master, watches the GitHub Actions build, and logs the deploy.
---

# Deploy — Florence with Locals

Deploys happen on a push to the `master` branch. Follow every step. Goal: zero broken deploys.

## Hard rules
1. Branch is `master`. Never `main` (abandoned, unrelated history).
2. Never modify payment logic (payments.php, Payments.jsx, payment calc/grouping) or passwords/secrets/.env*. If a diff touches these, STOP and ask the user.
3. Verify each changed file is COMPLETE (not truncated) before committing.
4. Log every deploy in DEPLOY_LOG.md.

## Steps
1. `git branch --show-current` (must be master) ; `git status` ; `git --no-pager diff --stat`
2. Review full diff: `git --no-pager diff`. Confirm intended changes only, and NO payment/password changes.
3. Verify completeness: `for f in $(git diff --name-only); do echo "== $f =="; tail -3 "$f"; done` — PHP must end with proper `}`/`?>`, JS/JSX with a complete statement. If anything is cut off, STOP.
4. Lint changed PHP: `for f in $(git diff --name-only -- 'public_html/api/*.php'); do php -l "$f"; done` — all must say "No syntax errors detected".
5. Stage intended files, then `git commit -m "<clear message>"`.
6. `git push origin master` (this triggers deploy).
7. Watch https://github.com/DhaNu1204/guide-florence-with-locals/actions until the run is green. If it fails, report the failing step's exact error.
8. Verify production: `curl -s -o /dev/null -w "%{http_code}\n" "https://withlocals.deetech.cc/api/tours.php?upcoming=true&per_page=1"` and the same for `https://withlocals.deetech.cc/` — both must be 200.
9. Append a row to DEPLOY_LOG.md: `| date time | short-sha | master | summary | Actions pass/fail |`
10. Report what shipped, Actions result, health check result.

## Reference
Repo: https://github.com/DhaNu1204/guide-florence-with-locals | Branch: master | Workflow: .github/workflows/deploy.yml | URL: https://withlocals.deetech.cc | Prod path: /home/u803853690/domains/deetech.cc/public_html/withlocals
Server-side sync relies on the Bokun webhook (api/bokun_webhook.php) + in-app 15-min sync. Hostinger cron does NOT work — don't depend on it. api/bokun_cron.php is a CLI-only inert fallback.
