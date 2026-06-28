# Florence with Locals Tour Guide Management System

> **CLAUDE CODE INSTRUCTIONS**: Read this file at the start of every session to understand the project context, architecture, and conventions.
>
> **Detailed docs** in `docs/` folder:
> - Environment/setup: `docs/ENVIRONMENT_SETUP.md`
> - Recent changes: `docs/CHANGELOG.md`
> - API reference: `docs/API_DOCUMENTATION.md`
> - Development commands: `docs/DEVELOPMENT_GUIDE.md`
> - Troubleshooting: `docs/TROUBLESHOOTING.md`
> - Architecture: `docs/ARCHITECTURE.md`
> - Bokun integration: `docs/BOKUN_INTEGRATION.md`
> - Technical report: `docs/TECHNICAL_REPORT.md`

## ⚠️ DEPLOYMENT — READ BEFORE ANY GIT/DEPLOY WORK

- **Deploy branch is `master`** (NOT `main`). `origin/main` is an old divergent branch with unrelated history — **never merge it, never deploy from it.**
- **Deploy by running `scripts/deploy.sh`** (Claude Code executes it): builds the frontend (vite), makes a remote backup, then SCP/rsync's backend + frontend to Hostinger. This is the live deploy path.
- **GitHub Actions auto-deploy is NOT active.** `.github/workflows/deploy.yml` exists and the SSH secrets (`SSH_HOST`/`SSH_PORT`/`SSH_USERNAME`/`SSH_PRIVATE_KEY`) **and a dedicated deploy key ARE configured** — but they go unused because **Hostinger blocks SSH (port 65002) from GitHub's runner IPs** (the TCP connection times out at the `Deploy Backend` step), so runner-based SSH deploys can't reach the server. The workflow is `workflow_dispatch`-only (no `push:` trigger). **Deploy via `scripts/deploy.sh` instead**, which works from this machine. To enable auto-deploy later: convert the workflow to FTPS upload, or use a self-hosted/static-IP runner Hostinger allows, then restore a `push:` trigger.
- **Use the `deploy` skill** in `.claude/skills/deploy/` for the full verified checklist. Always verify each changed file is COMPLETE (not truncated) before committing.
- **Never touch payment logic or passwords.**
- **Log every deploy** in `DEPLOY_LOG.md`.
- **Hostinger cron does not work** — server-side sync relies on the Bokun webhook (`api/bokun_webhook.php`) + in-app 15-min sync. Do not rely on Hostinger cron jobs.
- **Owner (Dhanu) does not use the terminal.** Cowork-Claude writes copy-paste prompts; Claude Code executes the git/terminal work.

## Project Overview

A tour guide management system for Florence, Italy. Integrates with Bokun API for automatic booking synchronization from OTA channels (Viator, GetYourGuide) and direct sales. Features tour grouping, group-aware payments, mobile responsive UI, and PDF reporting.

**Production**: https://withlocals.deetech.cc
**Status**: Fully Operational
**Last Updated**: March 3, 2026

## Tech Stack

- **Frontend**: React 18.2 + Vite 5.1 + TailwindCSS 3.4 (custom Tuscan theme)
- **Backend**: PHP 8.2 REST API
- **Database**: MySQL (40+ column tours table)
- **Integration**: Bokun REST API (HMAC-SHA1 auth)
- **PDF**: jsPDF + jsPDF-AutoTable
- **Monitoring**: Sentry.io (frontend + backend)
- **CI/CD**: GitHub Actions (build + deploy + health checks)
- **Testing**: Vitest + React Testing Library (52 tests)
- **Hosting**: Hostinger shared hosting (HTTPS)

## Key Features

1. **Tour Management**: CRUD with 40+ fields, filters (date, guide, upcoming/past/date range), pagination
2. **Guide Management**: Multi-language, pagination (20/page), RESTful PUT updates, DB indexes
3. **Payment System**: Group-aware (1 group = 1 payment), dedup prevention, 4 PDF reports, Europe/Rome TZ
4. **Bokun Integration**: Auto-sync every 15 min, SUPPLIER + SELLER roles, 200/page multi-page pagination
5. **Authentication**: bcrypt, 24h tokens, role-based (admin/viewer), Middleware-enforced on all endpoints
6. **Ticket Management**: Accademia museum tickets, 15-min intervals (08:15-17:30)
7. **Priority Tickets**: Museum ticket bookings with details modal, participant breakdown
8. **Mobile Responsive**: Dedicated mobile components, 44px touch targets, hamburger + card layout
9. **Tour Grouping**: Auto-group by product+date+time, max 9 PAX, DnD merge, advisory locks
10. **GYG Participant Names**: Regex extraction from special requests, expandable display, copy-all
11. **Cancelled/Rescheduled**: Auto-detection with visual indicators and audit trail
12. **Multi-Channel Language Detection**: Extracted from Bokun notes, rate titles, product titles
13. **Product Classification**: DB-driven product_type filtering (tour/ticket) via `products` table, replaces keyword matching
14. **Rate Limiting**: DB-backed, per-endpoint (login: 5/min, read: 100/min, write: 30/min)
15. **Error Tracking**: Sentry.io (100% trace, 10% replay) + file logging with rotation
16. **CI/CD Pipeline**: GitHub Actions: build verification + deploy to Hostinger + health checks
17. **Unassigned Tours Report**: Downloadable .txt report (date/time/location) of guideless tours, location extracted from title
18. **Guide Reports**: Per-guide monthly tour verification with category breakdown and PDF/CSV export (read-only)
19. **Needs-a-guide alert**: Dashboard alert for tours needing a guide in the next 7 days + double-booking guard on assignment
20. **Guide availability requests**: WhatsApp Accept/Decline links, language-matched guide picker, no-login guide page

## CRITICAL INFORMATION

### Development Rules
1. **PORTS**: Frontend: 5173 (always). Backend: 8080. Never change these.
   - Kill existing: `netstat -ano | findstr :5173` then `taskkill //PID [PID] //F`
2. **Database**: 40+ columns in `tours` table. Sessions table uses `token` column (not `session_token`).
3. **Config pattern**: All API files use `require_once 'config.php'` which provides `$conn`, DB vars.
4. **Authentication**: All API endpoints require `Middleware::requireAuth($conn)` (except auth.php login/logout).

### Environment Details
| Property | Development | Production |
|----------|-------------|------------|
| Frontend | http://localhost:5173 | https://withlocals.deetech.cc |
| Backend | http://localhost:8080 | https://withlocals.deetech.cc/api |
| Database | florence_guides | u803853690_withlocals |
| SSH | N/A | `ssh -p 65002 u803853690@82.25.82.111` |
| Admin Login | dhanu / Kandy@123 | dhanu / Kandy@123 |

### Key File Locations
- **Frontend Entry**: `src/main.jsx`
- **Main Layout**: `src/components/Layout/ModernLayout.jsx` (354 lines)
- **Largest Pages**: `src/pages/Payments.jsx` (2,125 lines), `src/pages/Tours.jsx` (1,698 lines)
- **API Service**: `src/services/mysqlDB.js` (601 lines, 1-min localStorage cache)
- **Database Config**: `public_html/api/config.php` (403 lines)
- **Bokun Client**: `public_html/api/BokunAPI.php` (705 lines)
- **Tour Groups**: `public_html/api/tour-groups.php` (1,006 lines)
- **Bokun Sync**: `public_html/api/bokun_sync.php` (1,015 lines)

## Project Structure

```
guide-florence-with-locals/
├── src/
│   ├── main.jsx                         # Sentry init, root mount
│   ├── App.jsx                          # Router, providers, protected routes
│   ├── components/
│   │   ├── Layout/ModernLayout.jsx      # Collapsible sidebar + mobile header
│   │   ├── UI/                          # Button (10 variants), Card, Input, StatusBadge
│   │   ├── BookingDetailsModal.jsx      # 6-section booking details (556 lines)
│   │   ├── TourGroup.jsx               # Expandable group, DnD, guide edit
│   │   ├── TourCardMobile.jsx          # Mobile tour card (4-row layout)
│   │   ├── TourGroupCardMobile.jsx     # Mobile group card (expand/collapse)
│   │   ├── BokunAutoSyncProvider.jsx   # 15-min auto-sync + status indicator
│   │   └── Dashboard.jsx               # Stats: unassigned, unpaid, upcoming
│   ├── pages/
│   │   ├── Tours.jsx                    # Grouping, DnD merge, filters, mobile (1,531 lines)
│   │   ├── Payments.jsx                 # 4 tabs, batch pay, PDF reports (2,125 lines)
│   │   ├── Guides.jsx                   # CRUD, pagination, multi-language (718 lines)
│   │   ├── Tickets.jsx                  # Accademia inventory, 15-min time slots
│   │   ├── PriorityTickets.jsx          # Ticket bookings with details modal
│   │   ├── BokunIntegration.jsx         # Sync triggers (admin-only)
│   │   └── Login.jsx                    # Username/password form
│   ├── contexts/
│   │   ├── AuthContext.jsx              # Token, role, login/logout
│   │   └── PageTitleContext.jsx         # Dynamic page titles
│   ├── services/
│   │   ├── mysqlDB.js                   # API layer, caching, axios interceptor (601 lines)
│   │   ├── bokunAutoSync.js             # Background sync service
│   │   └── ticketsService.js            # Tickets CRUD
│   ├── hooks/
│   │   └── useBokunAutoSync.jsx         # 15-min sync hook (199 lines)
│   └── utils/
│       ├── tourFilters.js               # Ticket vs tour keyword detection
│       └── pdfGenerator.js              # 4 PDF report types (Tuscan theme)
├── public_html/api/                     # PHP REST API
│   ├── config.php                       # DB, CORS, gzip, env detection, Sentry
│   ├── Middleware.php                   # Auth verification, CORS, logging
│   ├── auth.php                         # Login/logout/verify (bcrypt, 24h tokens)
│   ├── tours.php                        # Tour CRUD + filters + pagination
│   ├── guides.php                       # Guide CRUD + pagination + indexes
│   ├── tour-groups.php                  # Auto-group, merge, unmerge, dissolve (1,006 lines)
│   ├── payments.php                     # Payment transactions (group-aware dedup)
│   ├── guide-payments.php               # Per-guide summaries (group-aware counting)
│   ├── payment-reports.php              # Date-range reports (summary/detailed/monthly/export)
│   ├── tickets.php                      # Ticket inventory CRUD
│   ├── BokunAPI.php                     # Bokun client: HMAC-SHA1, multi-role, name parsing
│   ├── bokun_sync.php                   # Sync orchestrator: fetch, dedup, insert/update, auto-group
│   ├── bokun_webhook.php                # Webhook receiver
│   ├── RateLimiter.php                  # DB-backed rate limiting (IP + endpoint)
│   ├── Validator.php                    # Input validation (email, date, phone, etc.)
│   ├── Encryption.php                   # AES-256-CBC for API credentials
│   ├── EnvLoader.php                    # .env file parser
│   ├── BaseAPI.php                      # Standardized request/response
│   ├── Logger.php                       # File logging with rotation (5MB, 5 versions)
│   ├── SentryLogger.php                 # Sentry.io integration
│   └── HttpClient.php                   # Fallback HTTP client
├── database/migrations/                 # SQL migration files
├── scripts/                             # deploy.sh, deploy.ps1
├── docs/                                # 15 documentation files
├── .github/workflows/                   # main.yml (build), deploy.yml (deploy + health)
└── dist/                                # Production build (.htaccess for SPA routing)
```

## Architecture

### Provider Hierarchy
```
Sentry.ErrorBoundary
  └── Router (React Router v7.5)
        └── AuthProvider (token, role, login/logout)
              └── PageTitleProvider
                    └── BokunAutoSyncProvider (15-min background sync)
                          └── ModernLayout (sidebar/header)
                                └── Page Component
```

### Routes
| Path | Component | Access |
|------|-----------|--------|
| `/login` | Login | Public |
| `/respond/:token` | GuideRespond | **Public** — standalone, rendered OUTSIDE ProtectedRoute/ModernLayout (no sidebar/login) |
| `/` | Dashboard | Protected |
| `/tours` | Tours | Protected |
| `/guides` | Guides | Protected |
| `/tickets` | Tickets | Protected |
| `/payments` | Payments | Protected |
| `/guide-reports` | GuideReports | Protected |
| `/priority-tickets` | PriorityTickets | Protected |
| `/bokun-integration` | BokunIntegration | Admin only |

### Request Flow
1. Browser sends HTTPS + `Authorization: Bearer <token>`
2. `config.php` inits DB, CORS, gzip, Sentry, security headers
3. `Middleware::requireAuth($conn)` verifies Bearer token (all endpoints except auth login/logout)
4. `autoRateLimit()` enforces per-endpoint limits
5. Endpoint validates input, executes prepared statements, returns JSON (generic error messages only)
6. Frontend caches GET responses in localStorage (1-min TTL)

### Data Service Pattern (mysqlDB.js)
```javascript
// Exported functions
export const getTours = async (forceRefresh, page, perPage, filters) => { ... }
export const tourGroupsAPI = { list(), autoGroup(), manualMerge(), unmerge(), update(), dissolve() }

// Axios interceptor adds Bearer token from localStorage
// Cache: localStorage 'tours_v1' with 60-second TTL
// Anti-browser-cache: _={timestamp} query param
```

### Auth Fetch Pattern (for components using raw fetch)
```javascript
// Use this instead of bare fetch() in any component that calls API endpoints
const authFetch = (url, options = {}) => {
  const token = localStorage.getItem('token');
  return fetch(url, {
    ...options,
    headers: {
      ...options.headers,
      ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    }
  });
};
// Used in: Payments.jsx, Dashboard.jsx, PaymentRecordForm.jsx, ticketsService.js
// Prefer axios (via mysqlDB.js) for new code — it has the interceptor built in
```

## Tour Grouping System (Feb 2026)

### Business Rules
- Groups bookings that are same product + date + time
- Max 9 PAX per group (Uffizi museum rule)
- Auto-grouping runs after every Bokun sync
- Manual merges are never touched by auto-grouping (`is_manual_merge=1`)
- Assigning guide to group propagates to all tours in group
- 1 group = 1 payment unit (not per-booking)

### Database
- **`tour_groups`** table: id, group_date, group_time, display_name, guide_id, max_pax (9), is_manual_merge
- **`tours.group_id`** FK to tour_groups
- SQL pattern: `IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit`

### Integrity
- All group operations use database transactions (begin/commit/rollback)
- Advisory lock: `GET_LOCK('auto_group', 10)` prevents concurrent auto-grouping
- Orphan cleanup: deletes groups with no member tours

### Frontend
- `TourGroup.jsx`: Expandable row, guide edit, unmerge/dissolve, DnD target
- `TourGroupCardMobile.jsx`: Mobile expand/collapse, PAX fraction (X/9)
- Tours.jsx: HTML5 drag-and-drop, selection mode for mobile merge
- `groupedTours` memo: `periodGroup.items` array (tours + `{_isGroup, group}` objects)

### API
| Method | Endpoint | Action |
|--------|----------|--------|
| GET | `/api/tour-groups.php` | List groups |
| POST | `?action=auto-group` | Auto-group by product+date+time |
| POST | `?action=manual-merge` | Merge specific tour IDs |
| POST | `?action=unmerge` | Remove tour from group |
| PUT | `/{id}` | Update group (guide propagates) |
| DELETE | `/{id}` | Dissolve group |

## Guide Reports (Jun 2026)

Read-only month-end invoice verification — lists the tours a guide actually performed so the owner can check them against the guide's monthly invoice. **Touches no payment logic.** Group-aware (1 group = 1 tour unit), excludes cancelled tours and ticket products (same `products.product_type='ticket'` exclusion as guide-payments.php). Title-based category classification (Combo / Uffizi / Pitti / Accademia / Other) mirrors how guides reconcile on WhatsApp.

- **Backend**: `guide-tour-report.php` — `classifyTourCategory()` keyword rules (uffizi; accademia incl. "david"; pitti incl. boboli/palatina/palatine; 2+ museums = Combo)
- **Frontend**: `src/pages/GuideReports.jsx` (sidebar: Guide Reports), service `getGuideTourReport()` in mysqlDB.js
- **Exports**: PDF (jsPDF + autoTable) and Excel-compatible CSV (no xlsx dep)

### API
| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/api/guide-tour-report.php` | Auth required (read-only). Params: optional `guide_id`; `period=YYYY-MM` **or** `start=YYYY-MM-DD&end=YYYY-MM-DD`. With `guide_id`: returns `guide_info`, `total_tours`, `tours[]` (date/time/title/category) + `summary_by_category` (Combo/Uffizi/Pitti/Accademia/Other). Without `guide_id`: month overview `guides[]` (guide_id/guide_name/total_tours). |

## Guide Availability Requests (Jun 2026)

WhatsApp-based "ask a guide if they're available" flow. **Touches no payment logic.** The owner picks a guide for an unassigned tour (picker lists guides who speak the tour's language first), gets a pre-filled WhatsApp message + secret link, and the guide accepts/declines on a no-login page. Accepting assigns the guide (with double-booking + already-taken guards).

- **DB**: `availability_requests` table — self-provisioned via `CREATE TABLE IF NOT EXISTS` on first request to `guide-requests.php` (columns: id, tour_id, guide_id, token UNIQUE, status ENUM('pending','accepted','declined','cancelled','expired'), created_at, responded_at).
- **Frontend**: `src/components/AskGuideModal.jsx` (reusable; used on Tours page + Dashboard), public page `src/pages/GuideRespond.jsx` at `/respond/:token` (Italian, mobile, plain `fetch` — NOT the axios instance, so no Bearer token is sent). Services in mysqlDB.js: `createGuideRequest`, `getGuideRequests`, `getOpenGuideRequests`, `getRecentGuideResponses`.
- **Double-booking guard** also enforced on the tours.php PUT guide assignment (HTTP 409 `guide_double_booked` unless `force=true`).

### API — `guide-requests.php` (conditional auth)
A `token` query param routes to the **PUBLIC guide branch** (no auth); otherwise it's an **OWNER** request requiring `Middleware::requireAuth`.

| Mode | Method | Endpoint | Notes |
|------|--------|----------|-------|
| Owner | POST | `/api/guide-requests.php` `{tour_id, guide_id}` | Create (or reuse pending) request → `{id, token, status, link, message}` |
| Owner | POST | `{action:'cancel', id}` | Cancel a request |
| Owner | GET | `?tour_id=X` | List a tour's requests |
| Owner | GET | `?action=open` | Pending/declined requests for upcoming tours (for persistent badges) |
| Owner | GET | `?action=recent&days=N` | Recently accepted/declined responses (dashboard panel) |
| Public | GET | `?token=XYZ` | Minimal tour logistics only (date/time/title/language/participants/meeting_point) — **never customer PII** |
| Public | POST | `?token=XYZ` `{action:'accept'\|'decline'}` | Accept assigns the guide (double-booking + already-taken guards, expires sibling pendings); decline records it |

## Guide WhatsApp Reminders (Jun 2026)

Automatic Twilio WhatsApp reminder to the **assigned guide ~1 hour before each tour** so guides don't forget. **Touches no payment logic.** Built and live (`TWILIO_REMINDERS_ENABLED=true`).

### How it works
- **`twilio_reminders.php` → `reconcileGuideReminders($conn)`** schedules a **Twilio Scheduled Message** (Messaging Service + `ContentSid`, `ScheduleType=fixed`, `SendAt = tour start − TWILIO_GUIDE_REMINDER_LEAD_MIN`) for every tour that is **guide-assigned, non-cancelled, non-ticket, and starts within the next 7 days**. Tour start is computed in **Europe/Rome**; `SendAt` is sent to Twilio in UTC.
- On change (tour time moved, guide reassigned, tour cancelled/unassigned) it **cancels and reschedules / cancels** the existing scheduled message so the booked reminder always matches live data. Idempotent — safe to run repeatedly.
- **`guide_reminders` table** (self-provisioned via `CREATE TABLE IF NOT EXISTS`) tracks one row per tour: `tour_id` (UNIQUE), `guide_id`, `twilio_sid`, `send_at_utc`, `status` ENUM('scheduled','canceled','sent','failed'), `last_error`.

### Why reconcile on every sync (not cron)
- **Twilio's scheduled-message window is max 7 days out.** So reminders can't all be booked up front — they're **reconciled on EVERY sync**: hooked into the end of `syncBookings()` and into `tours.php` PUT (after a guide is assigned), **both exception-isolated** (a reminder failure can never break booking sync or guide assignment). A tour gets its reminder scheduled once it enters the 7-day window; **Twilio then fires the send at the exact time** — no dependence on Hostinger cron (which doesn't reliably fire on this host).

### Config (`.env`, read via EnvLoader / `config.php`)
- `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`
- `TWILIO_MESSAGING_SERVICE_SID` — `MGe678…` (WhatsApp sender `whatsapp:+17088408565` attached)
- `TWILIO_GUIDE_REMINDER_CONTENT_SID` — `HX04be4d…` (Meta-**approved** UTILITY template)
- `TWILIO_GUIDE_REMINDER_LEAD_MIN=60`
- `TWILIO_REMINDERS_ENABLED=true`

The Auth Token is used only for the HTTP Basic auth header — never logged or echoed.

### Template (Italian, approved UTILITY)
> `Ciao {{1}}, promemoria del tuo tour di oggi: {{2}} alle {{3}}. Grazie!`
>
> `{{1}}` = guide first name, `{{2}}` = tour title, `{{3}}` = start time (HH:MM).

### Failures
- A schedule failure (e.g. an **invalid guide phone number**) is recorded in `guide_reminders.last_error` with `status='failed'` and **swallowed** (never throws to the caller). It's **retried automatically on the next reconcile** once the underlying data is fixed (e.g. the guide's phone corrected in the Guides page). Phone normalization deliberately does **not** guess a country code — Italian mobiles stored without `+39` are treated as unusable until corrected.

## Tour Classification (Jun 2026)

`public_html/api/tour_classification.php` is the **single source of truth** for private-tour classification and per-product PAX capacity. Pure logic — no DB access, no side effects, no payment logic — safe to `require_once` anywhere.

### Functions
- **`isPrivateBooking($productId, $rateId, $rateTitle)`** → bool
- **`getMaxPaxForTitle($title)`** → 9 for `uffizi` (incl. Uffizi+Accademia combos), 19 for `accademia`/`david`, else 9
- **`bokunRateInfo($bokunData)`** → `[rateId, rateTitle]` (accepts a decoded array or JSON string)

### Private rules (the constants live in this file)
```php
FULLY_PRIVATE_PRODUCTS = [809837, 828971, 850642, 878643, 911547, 945194, 962886, 947299, 1145330, 1233544, 1115569];
MIXED_PRIVATE_RATES    = [962885 => [2266785], 1130528 => [2244449]];
```
- `productId` ∈ FULLY_PRIVATE_PRODUCTS → **private**.
- `productId` is a MIXED key → private only if `(int)rateId` is in that product's list; if rateId is empty/missing, fall back to `rateTitle` (trimmed, lowercased) containing `private`.
- else → not private.

### Where the values come from in `bokun_data`
- **rateId** = `productBookings[0].fields.rateId`  (⚠️ NOT top-level `productBookings[0].rateId`, which doesn't exist)
- **rateTitle** = `productBookings[0].rateTitle` (free text — has trailing-space/casing variants; trim + lowercase before matching)

### ➜ To add a new private product or rate
Edit the constants in `tour_classification.php` (add the product id to `FULLY_PRIVATE_PRODUCTS`, or add/extend the `MIXED_PRIVATE_RATES` rate-id list), then **re-run a backfill + regroup** so existing rows pick it up: a one-off CLI that loops `tours.bokun_data`, recomputes `is_private` via the helper, then calls `autoGroupAfterSync($conn, start, end)`. New bookings are classified automatically on the next sync.

## Tour Grouping (Jun 2026 — product-aware)

`autoGroupAfterSync($conn, $startDate, $endDate)` in `bokun_sync.php` (runs at the end of every `syncBookings`):

- **Group key = `product_id | date | HH:MM`** (was normalized title) — same product at the same departure groups together even when sold under different channel titles (e.g. 962885's "Uffizi & Accademia Walking Tour…" + "Uffizi, David Tour & Gelato…").
- **Excluded from auto-grouping** (left standalone, `group_id` NULL): `cancelled = 1`, `is_private = 1`, or `product_id IS NULL`. **Private tours are never auto-grouped.**
- **Rebuild model**: detaches all auto-group tours in range (manual merges, `is_manual_merge = 1`, are **untouched**), drops orphaned auto groups, then regroups from the candidates. Cancelled/private tours get `group_id` cleared.
- **`display_name`** = the **most-frequent title** among the group's bookings (tie → the title with most PAX), via `pickDisplayTitle()`.
- **Per-product capacity**: `getMaxPaxForTitle(display_name)` (Tour Classification) — sub-groups split when active PAX would exceed it; stored on `tour_groups.max_pax`.

### `tours.is_private` column
- `TINYINT(1) NOT NULL DEFAULT 0` + `idx_tours_is_private`. **Self-provisioned** by `ensureIsPrivateColumn($conn)` in `bokun_sync.php` and by `tours.php` (same `SHOW COLUMNS` guard pattern as `product_id`).
- Set on **every** insert/update in `bokun_sync.php` via `isPrivateBooking()`. Returned to the frontend through the existing `SELECT t.*` (no SELECT change).
- **Frontend**: purple **Private** badge (`bg-purple-100 text-purple-800`) on Tours rows + `TourCardMobile` (private tours render standalone with the normal guide-assign UI + Ask button). Cancelled bookings are excluded from PAX/booking/FULL counts (`src/utils/tourCapacity.js` — `getMaxPax`, `countActivePax`, `countActiveBookings`). Assigned + non-cancelled tours get a light-green background (priority: cancelled red > assigned green > default).

## Tours date filter (Jun 2026)

`src/components/DateFilter.jsx` — a themed in-app calendar replaces the native `<input type="date">` and the loose period buttons; `Tours.jsx` just renders `<DateFilter {...state/setters} />` (all existing filter state reused: `filterDate`, `showUpcoming`, `showPast`, `showDateRange`, `rangeStartDate`, `rangeEndDate`).
- **Trigger** button: calendar icon + selected date `EEE, d MMM yyyy`; "Pick a date" when in Upcoming/Past/Range mode; terracotta border/ring when open.
- **Popover calendar**: date-fns 6-row grid (`startOfMonth/endOfMonth/startOfWeek/endOfWeek/eachDayOfInterval`); selected day = terracotta filled, today = inset terracotta ring, out-of-month muted, hover stone. Outside-click + Escape close.
- **Month arrows land on the 1st** (`startOfMonth(addMonths/subMonths(filterDate||today, 1))`) and keep the popover open (owner's preferred behavior).
- **Segmented control**: Today / Upcoming / Past 40 Days / Date Range (active segment terracotta), wired to the same handlers; Range mode shows the two date inputs (`end >= start`).

## Guide phone validation (Jun 2026)

The Add/Edit Guide form (`src/pages/Guides.jsx`) requires an **international** phone so the WhatsApp tour reminder can actually send. `isValidGuidePhone(phone)` strips spaces/dashes/dots/parens then requires `/^(\+|00)\d{8,15}$/` (must start with `+` or `00` country code, then 8–15 digits; empty = invalid). On an invalid number the form shows an inline terracotta warning + a toast and **blocks save**. Bare local numbers (e.g. `3392863290`) and emails in the phone field are rejected. Pairs with the reminder backend, which deliberately won't guess a country code.

## Group-Aware Payment System (Feb 2026)

### SQL Pattern
```sql
-- Tour unit: group or individual
IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit

-- Unpaid: GROUP BY tour_unit HAVING MAX(p.id) IS NULL
-- Paid: GROUP BY tour_unit with INNER JOIN payments
```

### Duplicate Prevention
- `payments.php` checks if any tour in same group already has a payment → HTTP 409
- `force_group_payment: true` bypasses group duplicate check
- `force_payment: true` bypasses per-tour duplicate check

### Ticket Filtering in Payments
Uses `NOT EXISTS (SELECT 1 FROM products pr WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket')` — replaced 45 lines of `NOT LIKE` keyword matching across 9 locations.

## Product Classification System (Feb 2026)

### Overview
Classifies Bokun products as `tour` or `ticket` via a dedicated `products` table, replacing fragile keyword-based filtering (`NOT LIKE '%Entry Ticket%'` etc.) with reliable product ID lookups.

### Database
- **`products`** table: `bokun_product_id` (PK), `title`, `product_type` ENUM('tour','ticket'), timestamps
- **`tours.product_id`** column: FK to products, extracted from `bokun_data` JSON
- **Auto-migration**: `tours.php` auto-creates table/column on first request via `SHOW TABLES`/`SHOW COLUMNS` guards
- **Backfill**: One-time `JSON_EXTRACT` from `bokun_data` populates `product_id` for existing tours

### Known Ticket Product IDs
`809838`, `845665`, `877713`, `961802`, `1115497`, `1119143`, `1162586`

### Query Parameter
- `?product_type=tour` (default) — excludes tickets: `WHERE (pr.product_type = 'tour' OR t.product_id IS NULL)`
- `?product_type=ticket` — tickets only: `WHERE pr.product_type = 'ticket'`
- `?product_type=all` — no filter

### Sync Integration
- `BokunAPI.php` extracts `product_id` from `productBookings[0].product.id`
- `bokun_sync.php` auto-registers new products via `INSERT IGNORE INTO products`
- New products default to `product_type='tour'`

### Idempotent Ticket Classification (Mar 2026 fix)
- Known ticket IDs are enforced on **every request** to `tours.php` via `INSERT ... ON DUPLICATE KEY UPDATE`
- Previously, the classification only ran once during initial migration — products synced later were never reclassified
- The idempotent query handles all cases: new products inserted as `'ticket'`, mis-classified products corrected, already-correct rows are a no-op
- **Root cause of Borghese bug**: Product 1162586 was synced after the one-time migration, so `INSERT IGNORE` registered it as `'tour'`

### Files Modified
- **Backend**: `tours.php` (auto-migration + query param + idempotent classification), `bokun_sync.php` (product registration + product_id in INSERT/UPDATE), `BokunAPI.php` (product_id extraction), `guide-payments.php` (9x NOT LIKE → NOT EXISTS)
- **Frontend**: `Tours.jsx` (removed `filterToursOnly()`), `PriorityTickets.jsx` (added `product_type: 'ticket'`), `mysqlDB.js` (product_type param passthrough)
- **Migration**: `database/migrations/create_products_table.sql`

### Classify a New Product as Ticket
Add the product ID to the known ticket list in `tours.php` (the `INSERT ... ON DUPLICATE KEY UPDATE` statement near line 148). This ensures the classification is enforced on every request. For an immediate one-off fix:
```sql
UPDATE products SET product_type = 'ticket' WHERE bokun_product_id = <id>;
```

## GYG Participant Names (Feb 2026)

- **Source**: `productBookings[0].specialRequests` in GYG bookings
- **Format**: `"Traveler 1:\nFirst Name: X\nLast Name: Y\n..."`
- **Regex**: `/Traveler\s+(\d+):\s*\n?First Name:\s*(.+?)\s*\n?Last Name:\s*(.+?)(?:\n|$)/i`
- **Normalization**: `mb_convert_case(mb_strtolower(...), MB_CASE_TITLE)`
- **Storage**: `tours.participant_names` TEXT column (JSON array)
- **Frontend**: `ParticipantNamesCompact` — "First Last +N more" expandable
- **Backfill**: `bokun_sync.php?action=backfill-names`

## Bokun API Integration

### Authentication
- HMAC-SHA1 signature: `base64(hmac_sha1(Date + AccessKey + Method + Path, SecretKey))`
- Credentials encrypted with AES-256-CBC in `bokun_config` table

### Sync Mechanics
- Both SUPPLIER (OTA) and SELLER (direct) roles queried
- 200 bookings/page, up to 10 pages (2,000 max)
- Deduplicates by booking ID across roles
- Auto-groups after every sync
- Rate limited: 10/min

### Data Extraction
- **Time**: `startTimeStr` (local time, not UTC conversion)
- **Language**: From booking notes, rate title, or product title
- **Names**: Parsed from GYG special requests
- **Channel**: From `channel.title` or `seller.title`

### Auto-Sync Triggers
- On app startup (if stale > 15 min)
- Every 15 minutes (periodic)
- On app focus/visibility change
- Manual trigger (admin only)

## Bokun Sync Architecture (Jun 2026)

Hard-won facts from the sync overhaul (2026-06-22/23). The server-side mechanism is the **real-time webhook**, not Hostinger cron.

### Pagination — paginate by PAGE FULLNESS, not totalHits
`BokunAPI.php` `getBookings()`: Bokun's `totalHits` **under-reports** the true count (e.g. says 787 when there are 987). The old `((page+1)*pageSize) < totalHits` check stopped one page early and **silently dropped page-4+ bookings** (incl. cancelled/rescheduled ones, which then never updated). Now: **continue while a page returns a full `pageSize`** (a short page is the last one), with a 10-page (2,000-booking) safety cap.

### Sync window
- `DEFAULT_SYNC_DAYS` reduced **120 → 60**. The 120-day pass ran **145–267s** and got killed under rate/time limits, so syncs never completed. 60 days completes reliably.
- `FULL_SYNC_DAYS` **365** kept for occasional deep/manual sync.

### Cron — do NOT rely on it
- **Hostinger cron does not reliably fire on this account** — jobs added in hPanel never triggered. The webhook is the server-side freshness mechanism.
- `bokun_cron.php` (CLI entry point) had a **latent parse error since creation**: a `*/15` crontab example inside the docblock contained `*/`, closing the `/** */` comment early → file unparseable. Fixed; the schedule comment is now written as `0,15,30,45`.

### Webhook (`bokun_webhook.php`) — real-time, body-driven
- **History**: previously **500'd on EVERY call** — the `bokun_webhook_logs` table never existed in prod and `logWebhook()` ran *before* the try/catch, so the request died at the logging step before any handler. Now: **self-provisions `bokun_webhook_logs`** (CREATE TABLE IF NOT EXISTS, same pattern `tours.php` uses for `products`), **non-fatal logging**, and **always returns HTTP 200** (so Bokun never enters a retry storm).
- **Payload shape**: Bokun sends an **EMPTY `X-Bokun-Topic` header** (and empty `X-Bokun-Booking-Id`) — the header-based topic switch never matched. The event is the **full booking object in the BODY**: top-level `bookingId` / `status` / `confirmationCode` / `externalBookingReference` + **`activityBookings[]`** with **`startDateTime` (epoch ms)** / `startTime` / `date` / `dateString`. ⚠️ This is the **booking-detail shape**, NOT the `productBookings[]` / `startTimeStr` shape the polling/search path uses — do not assume they're interchangeable.
- **Flow**: read body → extract unique affected date(s) (Europe/Rome) from `activityBookings` → for each date call **`syncBookings(D, D, 'webhook', bookingId)`** through the **proven path** (reuses transform / match / reschedule / cancel — booking-search includes CANCELLED — product registration / auto-grouping). Marks the log row `processed=1` on success.
- **Dateless events skip the sync** (capture + 200 fast). Running a multi-day fallback could exceed the gateway timeout (504 → Bokun retries); the in-app 15-min sync catches anything a dateless event would miss.

### `bokun_sync.php` as a LIBRARY
Define `BOKUN_SYNC_LIB` **before** `require`-ing the file to expose `syncBookings()` without running the endpoint: the guard `if (php_sapi_name() !== 'cli' && !defined('BOKUN_SYNC_LIB'))` skips **both** the web auth/routing block **and** the top-level `applyRateLimit('bokun_sync')`. Used by the webhook (which keeps its own `webhook` 30/min limit, so no double-charge / 429-abort on bursts). CLI cron skips the same block via `php_sapi_name() === 'cli'`. Direct HTTP access stays fully authenticated + rate-limited.

### HOST NOTE — no PHP logs
**`log_errors` is `Off` server-wide**, so `error_log()` output is **NOT persisted anywhere** on this host. Debug via **DB side-effects** instead: `tours.last_sync` (bumped by a sync), `bokun_webhook_logs` (`processed` flag + captured `payload`), and the webhook's JSON response (`synced_dates`) — not PHP logs.

## API Rate Limiting

| Type | Limit | Window |
|------|-------|--------|
| login | 5 | 1 min |
| read (GET) | 100 | 1 min |
| write/create (POST) | 30 | 1 min |
| update (PUT) | 30 | 1 min |
| delete (DELETE) | 10 | 1 min |
| bokun_sync | 10 | 1 min |

```php
autoRateLimit('endpoint-name');       // Auto by HTTP method
applyRateLimit('login');              // Explicit type
applyRateLimit('custom', 50, 60);    // Custom: 50 per 60s
```

Response headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `Retry-After`

## PDF Reports

Located in `src/utils/pdfGenerator.js`. Tuscan theme with terracotta accent (#C75D3A).

| Function | Output |
|----------|--------|
| `generateGuidePaymentSummaryPDF()` | guide_payments_summary_YYYYMMDD.pdf |
| `generatePendingPaymentsPDF()` | pending_payments_YYYYMMDD.pdf |
| `generatePaymentTransactionsPDF()` | payment_transactions_YYYYMMDD.pdf |
| `generateMonthlySummaryPDF()` | monthly_payment_summary_YYYYMMDD.pdf |

## Mobile Responsive Design (Feb 2026)

- **Breakpoint**: 768px (`md`)
- **Pattern**: `hidden md:block` for desktop, `md:hidden` for mobile
- **Touch targets**: min 44x44px (WCAG)
- **Desktop**: Collapsible sidebar (64/256px) + data tables
- **Mobile**: Hamburger menu + card layouts
- **Components**: `TourCardMobile.jsx`, `TourGroupCardMobile.jsx`
- **Mobile merge**: Selection mode → floating bottom bar → "Merge Selected" + "Assign Guide"

## Security Hardening (Feb 2026)

### Authentication Enforcement
- All API endpoints require `Middleware::requireAuth($conn)` except auth.php login/logout
- Token verified via `sessions` table with expiry check
- Frontend axios interceptor reads `localStorage.getItem('token')` for Bearer header
- **IMPORTANT**: Components using raw `fetch()` must use the `authFetch()` wrapper (not bare `fetch()`) to include the Bearer token. The axios interceptor only covers `axios` calls. Fixed in: Payments.jsx, Dashboard.jsx, PaymentRecordForm.jsx, ticketsService.js

### CORS
- Handled exclusively by PHP in `config.php` (environment-aware origin checking)
- `.htaccess` does NOT set CORS headers (was removed — it overrode PHP logic)
- Production origins: `https://withlocals.deetech.cc`, `http://withlocals.deetech.cc`

### Error Information Policy
- Client responses use generic messages ("Failed to fetch tours", "An internal error occurred")
- Detailed errors go to `error_log()` only — never expose `$conn->error`, `$stmt->error`, or `$e->getMessage()`
- 404 responses do not leak request URI or endpoint names

### SQL Injection Prevention
- All queries use prepared statements with `bind_param()` — no string interpolation
- `guide-payments.php` was the last file converted from `real_escape_string` to prepared statements

### Security Headers
- Production: HSTS, CSP, X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- Development: Permissive CSP allowing localhost:* but still protective

### Session Management
- 24-hour token expiry
- Probabilistic expired session cleanup (5% chance on login)
- Tokens stored in `sessions` table with `expires_at` column

### .gitignore Protection
- Patterns block test/debug/migration/config files: `test_*.php`, `*_test.php`, `debug_*.php`, `check_*.php`, `fix_*.php`, `migrate_*.php`, `import_*.php`, `create_*.php`, `config_*_backup.php`

## Deployment

### Build config
- **Vite `base` must be `'/'` (absolute).** The app is served at the domain root (https://withlocals.deetech.cc/); a relative base (`'./'`) breaks directly-loaded deep routes like `/respond/:token` because the browser resolves assets against the route path (e.g. `/respond/assets/...`) and the JS never loads (blank page).

### CI/CD (GitHub Actions) — currently DISABLED
`.github/workflows/deploy.yml` is `workflow_dispatch`-only (no `push:` trigger). Auto-deploy is **not active** because Hostinger blocks SSH (port 65002) from GitHub's runner IPs, so the runner can't reach the server — even though the SSH secrets and dedicated deploy key are configured. **Use `scripts/deploy.sh` for deploys.** The workflow, if ever re-enabled, runs:
1. Build: npm ci + vite build + PHP syntax check
2. Deploy: SCP backend + rsync frontend to Hostinger (fails here from GitHub runners — SSH blocked)
3. Health check: curl tours/guides APIs (200 **or 401** = healthy, since auth is enforced) + frontend → HTTP 200

### Manual Deploy
```bash
npm run build
scp -P 65002 public_html/api/*.php u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/
scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/
```

### Production Server
| Property | Value |
|----------|-------|
| SSH | `ssh -p 65002 u803853690@82.25.82.111` |
| Web Root | /home/u803853690/domains/deetech.cc/public_html/withlocals |
| URL | https://withlocals.deetech.cc |
| Database | u803853690_withlocals |

## Common Development Patterns

### API Endpoint Pattern
```php
require_once 'config.php';
require_once 'Middleware.php';
Middleware::requireAuth($conn);
autoRateLimit('endpoint_name');
// GET/POST/PUT/DELETE switch
// Prepared statements with bind_param()
// JSON response: { success: true, data: [...] }
// Error responses: generic messages only (details to error_log)
```

### URL ID Extraction
```php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$lastSegment = end(explode('/', $path));
if (is_numeric($lastSegment)) { $id = intval($lastSegment); }
```

### Pagination Pattern
```php
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(1, min(500, intval($_GET['per_page'] ?? 50)));
// Returns: { data: [...], pagination: { current_page, per_page, total, total_pages, has_next, has_prev } }
```

### Classify a New Bokun Product as Ticket
```sql
UPDATE products SET product_type = 'ticket' WHERE bokun_product_id = <id>;
```
New products auto-register as `tour` during Bokun sync. No code changes needed — just update the DB row.

### Extend Auto-Sync Interval
Edit `src/hooks/useBokunAutoSync.jsx` → `SYNC_INTERVAL_MS`.

### Add New API Endpoint
```php
<?php
require_once 'config.php';
require_once 'Middleware.php';
Middleware::requireAuth($conn);
autoRateLimit('your_endpoint');
// Prepared statements + JSON response
// Never expose $conn->error or $e->getMessage() to client
```

## Working on This Project

1. **Starting**: Read this file, then `docs/CHANGELOG.md` for recent changes
2. **Before changes**: Check `docs/ARCHITECTURE.md`, verify DB schema
3. **Development**: Frontend in `src/`, backend in `public_html/api/`
4. **Testing**: `npm run test` (52 tests), test locally before deploying
5. **Ports**: Always 5173 (frontend) + 8080 (backend)
6. **When stuck**: Check `docs/TROUBLESHOOTING.md`

## Florence Skills Toolkit

Custom skills in `../florence-skills/` directory:

| Skill | Use For |
|-------|---------|
| UI Designer | Tuscan components, colors, styling |
| Mobile Optimizer | Responsive design, touch interactions |
| Security Hardener | Input validation, XSS/SQL injection prevention |
| Reliability Engineer | Error handling, monitoring, deployment |
| PHP Backend | API endpoints, database patterns |
| React Patterns | Component structure, hooks, contexts |
| Code Reviewer (Agent) | Security/performance review |
| Deployment Checker (Agent) | Pre-deployment validation |

---

**Last Updated**: March 3, 2026
**Production URL**: https://withlocals.deetech.cc
**Status**: Fully Operational
**Tests**: 52 passing (Vitest + React Testing Library)
**Repository**: https://github.com/DhaNu1204/guide-florence-with-locals.git
