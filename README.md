# Florence with Locals - Tour Guide Management System

A tour guide management system for Florence, Italy. Integrates with Bokun API for automatic booking synchronization from OTA channels (Viator, GetYourGuide) and direct sales.

**Production**: [https://withlocals.deetech.cc](https://withlocals.deetech.cc)
**Status**: Fully Operational
**Repository**: [github.com/DhaNu1204/guide-florence-with-locals](https://github.com/DhaNu1204/guide-florence-with-locals)

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | React 18.2 + Vite 5.1 + TailwindCSS 3.4 |
| Backend | PHP 8.2 REST API |
| Database | MySQL (40+ column tours table, 13+ tables) |
| Integration | Bokun REST API (HMAC-SHA1 auth) |
| PDF | jsPDF + jsPDF-AutoTable |
| Monitoring | Sentry.io (frontend + backend) |
| CI/CD | GitHub Actions (build + deploy + health checks) |
| Testing | Vitest + React Testing Library (52 tests) |
| Hosting | Hostinger shared hosting (HTTPS) |

## Key Features

- **Tour Management** - CRUD with 40+ fields, filters (date, guide, upcoming/past/date range), pagination
- **Tour Grouping** - Auto-group by product+date+time, max 9 PAX, drag-and-drop merge, advisory locks
- **Group-Aware Payments** - 1 group = 1 payment, duplicate prevention, 4 PDF reports, Europe/Rome TZ
- **Bokun Integration** - Auto-sync every 15 min, SUPPLIER + SELLER roles, 200/page pagination
- **Product Classification** - DB-driven tour/ticket filtering via `products` table (replaces keyword matching)
- **Guide Management** - Multi-language, pagination, RESTful updates
- **Priority Tickets** - Museum ticket bookings with details modal, participant breakdown
- **Mobile Responsive** - Dedicated mobile components, 44px touch targets, card layouts
- **GYG Participant Names** - Regex extraction from special requests, expandable display
- **Authentication** - bcrypt, 24h tokens, role-based (admin/viewer), Middleware-enforced
- **Rate Limiting** - DB-backed, per-endpoint (login: 5/min, read: 100/min, write: 30/min)
- **Security Hardening** - Prepared statements, auth on all endpoints, CORS, CSP, error leak prevention
- **Unassigned Tours Report** - Downloadable .txt report with date/time/location for guides
- **Error Tracking** - Sentry.io (100% trace, 10% replay) + file logging with rotation
- **CI/CD Pipeline** - GitHub Actions: build + deploy to Hostinger + health checks

## Code Metrics

| | Files | Lines of Code |
|-|-------|---------------|
| Frontend (React/JS) | 47 | 17,713 |
| Backend (PHP) | 31 | 10,868 |
| **Total** | **78** | **28,581** |

## Project Structure

```
guide-florence-with-locals/
├── src/                               # React frontend
│   ├── components/                    # 21 components (BookingDetailsModal, TourGroup, mobile cards, etc.)
│   ├── pages/                         # 9 pages (Tours, Payments, Guides, Tickets, PriorityTickets, etc.)
│   ├── services/                      # API layer (mysqlDB.js), Bokun sync, tickets
│   ├── contexts/                      # Auth, PageTitle
│   ├── hooks/                         # useBokunAutoSync (15-min interval)
│   └── utils/                         # PDF generator, tour filters, date formatting
├── public_html/api/                   # PHP REST API
│   ├── config.php                     # DB, CORS, gzip, Sentry, security headers
│   ├── Middleware.php                 # Auth verification, CORS, responses
│   ├── tours.php                      # Tour CRUD + product_type filtering
│   ├── tour-groups.php                # Auto-group, merge, unmerge, dissolve
│   ├── payments.php                   # Group-aware payment transactions
│   ├── guide-payments.php             # Per-guide summaries
│   ├── bokun_sync.php                 # Sync orchestrator + auto-group
│   ├── BokunAPI.php                   # Bokun client (HMAC-SHA1, multi-role)
│   ├── RateLimiter.php                # DB-backed rate limiting
│   └── ...                            # auth, guides, tickets, validators, encryption
├── database/migrations/               # SQL migration files
├── docs/                              # 15 documentation files
│   ├── CHANGELOG.md                   # Recent changes
│   ├── TECHNICAL_REPORT.md            # Comprehensive technical report
│   └── ...                            # Architecture, API docs, troubleshooting
├── .github/workflows/                 # CI/CD pipeline
└── dist/                              # Production build
```

## Database

| Table | Purpose |
|-------|---------|
| tours | Bookings (40+ columns, Bokun integration) |
| tour_groups | Group by product+date+time, max 9 PAX |
| products | Product classification (tour/ticket) |
| payments | Payment transactions (group-aware) |
| guides | Guide profiles, multi-language |
| users / sessions | Auth (bcrypt, 24h tokens) |
| tickets | Accademia museum inventory |
| rate_limits | DB-backed rate limiting |
| bokun_config | Encrypted API credentials |

## API Endpoints

| File | Methods | Purpose |
|------|---------|---------|
| auth.php | POST | Login/logout/verify (bcrypt, 24h tokens) |
| tours.php | GET/POST/PUT/DELETE | Tour CRUD + product_type filter + pagination |
| tour-groups.php | GET/POST/PUT/DELETE | Auto-group, merge, unmerge, dissolve |
| payments.php | GET/POST/PUT/DELETE | Group-aware payment transactions |
| guide-payments.php | GET | Per-guide summaries, pending tours |
| payment-reports.php | GET | Date-range reports (summary/detailed/monthly/export) |
| guides.php | GET/POST/PUT/DELETE | Guide CRUD + pagination |
| tickets.php | GET/POST/PUT/DELETE | Ticket inventory CRUD |
| bokun_sync.php | GET | Bokun sync + backfill-names |
| bokun_webhook.php | POST | Webhook receiver |

All endpoints require `Middleware::requireAuth()` (except auth login/logout).

## Development Setup

### Prerequisites
- Node.js 16+ with npm
- PHP 8.2+ (curl, openssl, mysqli)
- MySQL 8.0+

### Quick Start
```bash
git clone https://github.com/DhaNu1204/guide-florence-with-locals.git
cd guide-florence-with-locals
npm install

# Frontend (port 5173)
npm run dev

# Backend (port 8080)
cd public_html && php -S localhost:8080
```

### Environment
| Property | Development | Production |
|----------|-------------|------------|
| Frontend | http://localhost:5173 | https://withlocals.deetech.cc |
| Backend | http://localhost:8080 | https://withlocals.deetech.cc/api |
| Database | florence_guides | u803853690_withlocals |

### Testing
```bash
npm test              # Watch mode
npm run test:run      # Single run
npm run test:coverage # Coverage report
```

## Deployment

### CI/CD (GitHub Actions)
Push to `main` triggers: build (npm ci + vite build + PHP syntax check) -> deploy (SCP + rsync) -> health check (curl API + frontend).

### Manual Deploy
```bash
npm run build
scp -P 65002 public_html/api/*.php u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/
scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/
```

## Security

- **Auth**: bcrypt passwords, 24h Bearer tokens, Middleware-enforced on all endpoints
- **SQL**: All queries use prepared statements with `bind_param()` — zero string interpolation
- **CORS**: PHP-only (config.php), environment-aware origin checking
- **Rate Limiting**: DB-backed, per-endpoint, with `X-RateLimit-*` response headers
- **Errors**: Generic client messages only; details to `error_log()` exclusively
- **Headers**: HSTS, CSP, X-Content-Type-Options, X-Frame-Options, X-XSS-Protection
- **Sessions**: 24h expiry, probabilistic cleanup (5% on login)
- **.gitignore**: Blocks test/debug/migration/config backup files

## Documentation

See `docs/` for detailed documentation:
- `TECHNICAL_REPORT.md` - Comprehensive technical report with code metrics
- `CHANGELOG.md` - Recent changes and deployment history
- `ARCHITECTURE.md` - System architecture details
- `API_DOCUMENTATION.md` - Full API reference
- `BOKUN_INTEGRATION.md` - Bokun API integration details
- `ENVIRONMENT_SETUP.md` - Environment configuration
- `DEVELOPMENT_GUIDE.md` - Development commands and workflows
- `TROUBLESHOOTING.md` - Common issues and solutions

---

**Last Updated**: February 24, 2026
**Production**: [https://withlocals.deetech.cc](https://withlocals.deetech.cc)
