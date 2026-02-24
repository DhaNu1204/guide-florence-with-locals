# Florence with Locals — Technical Report

**Generated:** February 24, 2026 | **Production:** https://withlocals.deetech.cc | **Repository:** github.com/DhaNu1204/guide-florence-with-locals

---

## 1. Code Metrics

| Layer | Files | Lines of Code | Largest File |
|-------|-------|---------------|--------------|
| Frontend (React/JS) | 47 | 17,713 | Payments.jsx (2,125) |
| Backend (PHP) | 31 | 10,868 | bokun_sync.php (1,014) |
| **Total** | **78** | **28,581** | |

---

## 2. Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | React + Vite + TailwindCSS | 18.2 / 5.1 / 3.4 |
| Routing | React Router | 7.5 |
| HTTP Client | Axios | 1.8.4 |
| PDF | jsPDF + AutoTable | 4.0 / 5.0 |
| Backend | PHP | 8.2 |
| Database | MySQL | Hostinger shared |
| Monitoring | Sentry.io | 10.36 (100% trace, 10% replay) |
| Testing | Vitest + React Testing Library | 4.0 / 16.3 |
| CI/CD | GitHub Actions | Push-to-main deploy |
| Integration | Bokun REST API | HMAC-SHA1 auth |
| Hosting | Hostinger shared hosting | HTTPS |

---

## 3. Database Schema

### Core Tables

**tours** (40+ columns)
- `id`, `title`, `date`, `time`, `customer_name`, `customer_email`, `customer_phone`, `participants`, `participant_names` (JSON), `language`, `booking_channel`, `product_id` (FK), `group_id` (FK), `guide_id` (FK), `cancelled`, `rescheduled`, `original_date`, `original_time`, `payment_status`, `notes`, `bokun_data` (JSON), `bokun_booking_id`, timestamps...
- Indexes: date, guide_id, group_id, product_id

**tour_groups**
- `id`, `group_date`, `group_time`, `display_name`, `guide_id`, `max_pax` (9), `total_pax`, `is_manual_merge`, timestamps
- Indexes: date, date+time, guide

**products**
- `bokun_product_id` (PK), `title`, `product_type` ENUM('tour','ticket'), timestamps
- 7 known ticket IDs: 809838, 845665, 877713, 961802, 1115497, 1119143, 1162586

**payments**
- `id`, `tour_id` (FK), `guide_id` (FK), `amount`, `payment_method`, `payment_date`, `notes`, timestamps
- Group-aware via `IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id))` tour_unit pattern

**guides** — `id`, `name`, `email`, `phone`, `languages` (comma-separated), `notes`

**users** — `id`, `username`, `password` (bcrypt), `role` ENUM('admin','viewer')

**sessions** — `id`, `user_id`, `token` (unique), `expires_at` (24h TTL)

**tickets** — Museum inventory with status ENUM('available','reserved','used','expired')

**Supporting:** `rate_limits`, `login_attempts`, `bokun_config` (encrypted credentials), `sync_logs`

---

## 4. API Endpoints (28 endpoints across 12 files)

### Authentication (`auth.php`)
| Method | Endpoint | Auth | Rate Limit |
|--------|----------|------|------------|
| POST | `?action=login` | None | 5/min |
| POST | `?action=logout` | Bearer | 10/min |
| GET | `?action=verify` | Bearer | 10/min |

### Tours (`tours.php`)
| Method | Params | Purpose |
|--------|--------|---------|
| GET | `page`, `per_page`, `date`, `guide_id`, `upcoming`, `past`, `start_date`, `end_date`, `product_type` | List with filters (default excludes tickets) |
| POST | — | Create tour |
| PUT | `?id=X` | Update tour |
| DELETE | `?id=X` | Delete tour |

### Tour Groups (`tour-groups.php`)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | — | List groups |
| POST | `?action=auto-group` | Auto-group by product+date+time |
| POST | `?action=manual-merge` | Merge specific tour IDs |
| POST | `?action=unmerge` | Remove tour from group |
| PUT | `/{id}` | Update group (guide propagates) |
| DELETE | `/{id}` | Dissolve group |

### Payments (`payments.php`) — Full CRUD, group-aware dedup (HTTP 409)

### Guide Payments (`guide-payments.php`) — Per-guide summaries, pending tours, group-aware counting

### Payment Reports (`payment-reports.php`) — summary/detailed/monthly/export by date range

### Guides (`guides.php`) — Full CRUD with pagination (20/page)

### Tickets (`tickets.php`) — Full CRUD, 15-min intervals for Accademia

### Bokun Sync (`bokun_sync.php`) — `?action=sync` (dual-role fetch + auto-group), `?action=backfill-names`

### Bokun Webhook (`bokun_webhook.php`) — POST receiver for booking updates

---

## 5. Frontend Architecture

### Provider Hierarchy
```
Sentry.ErrorBoundary
  └── Router (React Router v7.5)
        └── AuthProvider (token, role)
              └── PageTitleProvider
                    └── BokunAutoSyncProvider (15-min sync)
                          └── ModernLayout (sidebar/header)
                                └── Page Component
```

### Pages (9 pages, 7,771 LOC)
| Page | LOC | Key Features |
|------|-----|--------------|
| Payments.jsx | 2,125 | 4 tabs, batch pay, PDF reports |
| Tours.jsx | 1,698 | Grouping, DnD merge, filters, mobile layout |
| Tickets.jsx | 1,396 | Museum slots, 15-min intervals |
| PriorityTickets.jsx | 786 | Participant breakdown, booking details modal |
| Guides.jsx | 718 | CRUD, pagination, multi-language |
| EditTour.jsx | 485 | Full 40+ field tour editor |
| BokunIntegration.jsx | 281 | Admin-only sync controls |
| Login.jsx | 94 | Auth form |

### Components (21 files, 6,208 LOC)
Key: BookingDetailsModal (556), Dashboard (450), BokunMonitor (424), ModernLayout (354), TourGroup (301), TourGroupCardMobile (277), TourCardMobile (252)

### Services (5 files, 1,749 LOC)
- **mysqlDB.js** (609): Main API service, axios interceptor, localStorage cache (60s TTL)
- **bokunAutoSync.js** (268): 15-min background sync with focus/visibility triggers
- **ticketsService.js** (320): Ticket CRUD

### Utils (4 files, 1,405 LOC)
- **pdfGenerator.js** (600): 4 Tuscan-themed PDF report types
- **bokunDataExtractors.js** (408): Language, names, channel extraction from Bokun data
- **tourFilters.js** (143): Product classification helpers

---

## 6. Key Feature Systems

### Tour Grouping
- Auto-groups by product+date+time after every Bokun sync
- Max 9 PAX per group (Uffizi museum rule)
- Manual merges protected from auto-grouping (`is_manual_merge=1`)
- Guide assignment propagates to all tours in group
- All operations in DB transactions with advisory locks (`GET_LOCK('auto_group', 10)`)
- Orphan cleanup after every auto-group
- HTML5 drag-and-drop merge on desktop, selection mode on mobile

### Group-Aware Payments
- 1 group = 1 payment unit (not per-booking)
- Duplicate prevention: checks if any tour in group already paid (HTTP 409)
- Ticket exclusion via `NOT EXISTS` subquery against products table
- 4 PDF report types: guide summary, pending, transactions, monthly

### Product Classification
- `products` table classifies Bokun products as `tour` or `ticket`
- Replaces 45 lines of `NOT LIKE` keyword matching across 9 locations
- `?product_type=tour` (default) / `ticket` / `all` query parameter
- New products auto-register as `tour` during Bokun sync
- Auto-migration: table/column created on first request

### Bokun Integration
- Dual-role sync: SUPPLIER (OTA) + SELLER (direct), deduplicates by booking ID
- 200/page, up to 10 pages (2,000 bookings max)
- HMAC-SHA1 authentication, AES-256-CBC encrypted credentials
- Extracts: time, language, participant names (GYG), channel, product ID
- 15-min auto-sync with focus/visibility triggers

### Mobile Responsive
- Breakpoint: 768px (`md`), dedicated mobile components
- 44px min touch targets (WCAG), card layouts replace tables
- Selection mode merge with floating bottom action bar

---

## 7. Security

### Authentication & Authorization
- bcrypt password hashing, 24h Bearer tokens
- `Middleware::requireAuth($conn)` on all endpoints (except login/logout)
- Role-based: admin/viewer
- Probabilistic session cleanup (5% on login)

### Input Validation
- All queries: prepared statements with `bind_param()` — zero string interpolation
- `Validator.php`: required, email, date, time, maxLength, in() checks
- Frontend: React auto-escapes JSX

### Rate Limiting (DB-backed)
| Type | Limit |
|------|-------|
| Login | 5/min |
| Read (GET) | 100/min |
| Write (POST) | 30/min |
| Update (PUT) | 30/min |
| Delete | 10/min |
| Bokun sync | 10/min |

Response headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `Retry-After`

### Error Disclosure Policy
- Client responses: generic messages only
- `$conn->error`, `$stmt->error`, `$e->getMessage()` — never exposed, written to `error_log()` only
- 404 responses don't leak URIs or endpoint names

### CORS
- PHP-only (config.php), environment-aware origin checking
- `.htaccess` does NOT set CORS headers
- Production: `https://withlocals.deetech.cc`

### Security Headers
- Production: HSTS, CSP, X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- `.gitignore`: blocks `test_*.php`, `debug_*.php`, `check_*.php`, `fix_*.php`, `config_*_backup.php`

---

## 8. Testing

| Suite | Tests | Framework |
|-------|-------|-----------|
| Button.jsx | 23 | Vitest + Testing Library |
| Login.jsx | 16 | Vitest + Testing Library |
| mysqlDB.js | 13 | Vitest |
| **Total** | **52** | All passing |

```bash
npm test              # Watch mode
npm run test:run      # Single run
npm run test:coverage # Coverage report
```

---

## 9. CI/CD Pipeline (GitHub Actions)

**Trigger:** Push to `main` branch or manual `workflow_dispatch`

| Job | Steps |
|-----|-------|
| **Build** | Checkout → Node 18 → npm ci → vite build → upload artifact |
| **Deploy** | SCP PHP files + rsync frontend dist → chmod permissions |
| **Health Check** | Wait 10s → curl tours API (200) → curl guides API (200) → curl frontend (200) |

**Manual deploy:**
```bash
npm run build
scp -P 65002 public_html/api/*.php u803853690@82.25.82.111:.../withlocals/api/
scp -P 65002 -r dist/* u803853690@82.25.82.111:.../withlocals/
```

---

## 10. Top 10 Largest Files

| Rank | File | LOC |
|------|------|-----|
| 1 | src/pages/Payments.jsx | 2,125 |
| 2 | src/pages/Tours.jsx | 1,698 |
| 3 | src/pages/Tickets.jsx | 1,396 |
| 4 | public_html/api/bokun_sync.php | 1,014 |
| 5 | public_html/api/tour-groups.php | 1,007 |
| 6 | src/pages/PriorityTickets.jsx | 786 |
| 7 | src/components/PaymentRecordForm.jsx | 736 |
| 8 | public_html/api/guide-payments.php | 731 |
| 9 | src/pages/Guides.jsx | 718 |
| 10 | public_html/api/BokunAPI.php | 709 |

---

## 11. Summary

```
Total Codebase:       28,581 LOC across 78 production files
Frontend:             17,713 LOC (47 files) — React 18 + Vite + TailwindCSS
Backend:              10,868 LOC (31 files) — PHP 8.2 REST API
Database:             13+ tables, 40+ column tours table
API Endpoints:        28 across 12 PHP files
Components:           21 React components
Pages:                9 routes (1 public, 7 protected, 1 admin-only)
Tests:                52 passing (Vitest + React Testing Library)
Security:             Auth middleware, rate limiting, prepared statements, CSP headers
Mobile:               Full responsive with dedicated mobile components
Integration:          Bokun API (HMAC-SHA1, dual-role, 15-min auto-sync)
CI/CD:                GitHub Actions (build → deploy → health check)
Production:           https://withlocals.deetech.cc — fully operational
```
