---
name: implement-step
description: Implement ONE numbered step from docs/IMPLEMENTATION_PLAN.md end-to-end — branch, change, verify locally, deploy to staging, smoke test, merge, deploy to production, tick the step. Use when asked to "do step X", "implement step X", or "continue the plan".
---

# Implement one plan step

You are working on the Florence With Locals Tour Management System (React/Vite SPA + PHP 8 API + MySQL on Hostinger). The rule of this repo: **one step at a time, verified, nothing else touched.**

## Inputs
- `$ARGUMENTS` = the step id (e.g. `3.5`). If missing, open `docs/IMPLEMENTATION_PLAN.md` and pick the first unticked step in the lowest unfinished phase; confirm it with the user before starting.

## Procedure

1. **Read** the step in `docs/IMPLEMENTATION_PLAN.md` and, if it cites `§x.y`, the matching finding in `TECHNICAL_ANALYSIS_2026-09-10.md`. Restate the change and the Verify checklist in 3–5 lines. If any step it depends on (listed in the plan text) is unticked, stop and say so.
2. **Preconditions**: `git status --porcelain` must be empty and you must be on `master` (never `main` — that is an old divergent branch). Then `git checkout -b step/<id>`.
3. **Implement only what the step says.** No refactors, renames, or formatting of untouched code. Prepared statements for all SQL. New endpoints get `Middleware::requireAuth` + `requireRole('admin')` for writes. New tables are created in a numbered file under `database/migrations/` (and, until Phase 8 lands, also self-provisioned with `CREATE TABLE IF NOT EXISTS` where the existing code does that).
4. **Local verification** (all must pass):
   - `for f in $(git diff --name-only master -- '*.php'); do php -l "$f"; done`
   - `npm run test:run` — add or extend a vitest test when the step touches `src/utils`, `src/services` or a component with logic.
   - `npm run build`
   - Walk the step's **Verify** checklist against the local stack (`npm run dev` + `php -S localhost:8080 -t public_html`). Record the actual values you observed (counts, payload sizes, HTTP codes).
5. **Staging**: run `/staging-deploy staging`. It deploys and runs the smoke test; then repeat the step's Verify checklist against staging. Any failure → fix on the branch and repeat from 4; never proceed with a red check.
6. **Ship**: commit as `step <id>: <one line>` with a body listing what was verified and the numbers observed. `git checkout master && git merge --no-ff step/<id>`. Run `/staging-deploy production`. Re-run the smoke test on production.
7. **Close**: tick the step (`- [x]` / table row note) in `docs/IMPLEMENTATION_PLAN.md`, append one line to `docs/CHANGELOG.md` (`YYYY-MM-DD step <id> — <what> — verified: <how>`), commit on `master`.
8. **Report** to the user in ≤10 lines: what changed (files), what was verified with the observed values, anything noticed but deliberately NOT changed (goes to the plan as a note, not into the code).

## Rollback
If production smoke test fails: `scripts/deploy.sh --target production --restore-last-backup`, then `git revert` the merge commit, and tell the user exactly which check failed.

## Never
- Never deploy to production from a `step/*` branch.
- Never edit `.env*` on the server from a step; env changes are listed for the user to apply and confirmed with `health.php`.
- Never print secrets (tokens, keys, passwords) in output, commits or logs.
