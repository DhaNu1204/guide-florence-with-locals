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

## Project Overview

A tour guide management system for Florence, Italy. Integrates with Bokun API for automatic booking synchronization from OTA channels (Viator, GetYourGuide) and direct sales. Features tour grouping, group-aware payments, mobile responsive UI, and PDF reporting.

**Production**: https://withlocals.deetech.cc
**Status**: Fully Operational
**Last Updated**: February 25, 2026

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
| `/` | Dashboard | Protected |
| `/tours` | Tours | Protected |
| `/guides` | Guides | Protected |
| `/tickets` | Tickets | Protected |
| `/payments` | Payments | Protected |
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

### Files Modified
- **Backend**: `tours.php` (auto-migration + query param), `bokun_sync.php` (product registration + product_id in INSERT/UPDATE), `BokunAPI.php` (product_id extraction), `guide-payments.php` (9x NOT LIKE → NOT EXISTS)
- **Frontend**: `Tours.jsx` (removed `filterToursOnly()`), `PriorityTickets.jsx` (added `product_type: 'ticket'`), `mysqlDB.js` (product_type param passthrough)
- **Migration**: `database/migrations/create_products_table.sql`

### Classify a New Product as Ticket
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

### CI/CD (GitHub Actions)
Push to `main` triggers:
1. Build: npm ci + vite build + PHP syntax check
2. Deploy: SCP backend + rsync frontend to Hostinger
3. Health check: curl tours API, guides API, frontend → HTTP 200

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

**Last Updated**: February 25, 2026
**Production URL**: https://withlocals.deetech.cc
**Status**: Fully Operational
**Tests**: 52 passing (Vitest + React Testing Library)
**Repository**: https://github.com/DhaNu1204/guide-florence-with-locals.git
