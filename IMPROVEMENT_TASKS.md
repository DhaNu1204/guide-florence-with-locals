# Improvement Tasks — Florence with Locals

Work through these one by one. Mark `[x]` when done.
Order: features that make daily operations easier first, then security cleanup, then codebase health.

---

## Phase A — Daily Operations Features

### Task 1: Live booking updates (no more logout/login) — ✅ DEPLOYED 2026-06-13
**Problem:** New Bokun bookings sync to the database every 15 min, but the Tours page keeps showing old cached data until you log out (logout clears the cache).
**Fix:**
- [x] When a Bokun sync finishes, automatically clear the tour cache and reload the Tours page + Dashboard data (`bokunAutoSync.js`, `Tours.jsx`, `Dashboard.jsx`)
- [x] "Refresh" button on Tours now also triggers a Bokun sync (not just a database re-read)
- [x] Returning to the browser tab already triggers a sync if stale (>15 min) — the new auto-reload now makes the results visible
**Done when:** A new booking appears on screen within ~15 min (or instantly after pressing Refresh) without logging out.

### Task 2: Server-side sync (bookings sync even when app is closed)
**Problem:** Sync only runs while an admin has the app open in a browser. Overnight/weekend bookings wait until you open the app.
**Fix:** Hostinger cron job calling `bokun_sync.php` every 15 min + verify Bokun webhook is active for instant updates.
**Done when:** Opening the app in the morning shows last night's bookings immediately.
- [x] CLI cron entry point added (`bokun_cron.php`) + CLI guard in `bokun_sync.php` (web endpoint stays authenticated) — 2026-06-13
- [ ] Create the Hostinger cron job in hPanel (every 15 min) — *manual step, see instructions*
- [ ] Verify Bokun webhook is registered + active in the Bokun dashboard — *manual step*

### Task 3: New-booking visibility
**Fix:** Toast/badge showing "3 new bookings since you last looked" with quick link; highlight newly arrived tours in the list.

### Task 4: Unassigned-tour alerts ✅ DONE
**Fix:** Clear warning (dashboard + optional daily summary) for upcoming tours within X days that still have no guide assigned.
- [x] Dashboard "Tours needing a guide — next 7 days" alert (2026-06-15) — fulfilled by Guide availability Phase 1 (see Task 5).

### Task 5: Your next feature ideas
Add here as they come up (e.g. guide availability calendar, WhatsApp-ready daily schedule message, etc.)

- [x] Guide Reports (2026-06-15) — read-only month-end invoice verification. New sidebar page + guide-tour-report.php API. Per-guide monthly tour list (date/time/tour), group-aware count (1 group = 1 tour), excludes cancelled + ticket products. Category breakdown chips + Type column (Combo / Uffizi / Pitti / Accademia / Other) matching how guides reconcile on WhatsApp. PDF + Excel/CSV export. Verified: Caterina May 2026 = 20 Combo, 10 Uffizi, 2 Pitti, 1 Accademia.
- [x] Guide availability — Phase 1 (2026-06-15): Dashboard "Tours needing a guide — next 7 days" alert (each row deep-links to that tour's date on the Tours page); double-booking guard on guide assignment (warns when the guide already has a tour at the same date/time, with override). Also fixed Vite base to '/' so directly-loaded deep routes work.
- [x] Guide availability — Phase 2 (2026-06-15): per-tour WhatsApp Accept/Decline links. Owner "Ask a guide" picker (shows guides who speak the tour's language first) on Tours page + dashboard → pre-filled WhatsApp (wa.me) message with a secret link → guide opens a no-login mobile page at /respond/<token> → Accept (auto-assigns with double-booking + already-taken guards) or Decline. Backend: availability_requests table + guide-requests.php (owner endpoints + public token endpoint). Pending/declined badges on tours; dashboard "recent guide responses" panel.

---

## Phase B — Security Cleanup (proper way; no password rotation per Dhanu's decision)

### Task 6: Remove credentials from the repo
- [ ] Delete the 6 debug PHP files containing the hardcoded production DB password
- [ ] Remove admin login + SSH details from `CLAUDE.md` (reference a local untracked file instead)
- [ ] Stop tracking `.env`, `.env.local`, `.env.production` (keep only `.example` files)

### Task 7: Lock down production webroot
- [ ] Remove/block debug, test, and `migrate_*.php` endpoints from the live site

---

## Phase C — Codebase Health

### Task 8: Repo cleanup
- [ ] Delete dead Node.js backend (`backend/`, `server/`)
- [ ] Move loose root-level SQL/PHP debug scripts into `scripts/archive/` (or delete)
- [ ] Move the ~37 status-report .md files into `docs/history/`

### Task 9: Tests for the payment system
Backend + frontend tests for payment calculation/grouping — highest financial risk area.

### Task 10: Split oversized files (gradual)
`Payments.jsx` (2,125 lines) and `Tours.jsx` (1,698 lines) — split into smaller components as we touch them.

---

---

## Next Session Prompt (copy-paste to Claude)

> Open IMPROVEMENT_TASKS.md in the guide-florence-with-locals project and continue the plan.
>
> Today's session:
> 1. Finish Task 1 (live booking updates): the code changes in bokunAutoSync.js, Tours.jsx and Dashboard.jsx are already done but not deployed. Help me commit and push them so GitHub Actions deploys to production, then tell me exactly how to test that new bookings appear without logging out.
> 2. Then start Task 2 (server-side sync): set up a Hostinger cron job that calls bokun_sync.php every 15 minutes so bookings sync even when nobody has the app open, and verify the Bokun webhook is active for instant updates.
>
> Rules: don't change any passwords, don't touch the payment logic, and update IMPROVEMENT_TASKS.md checkboxes when something is completed.

---

*Created 2026-06-13 with Claude. Update this file as tasks complete.*
