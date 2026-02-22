# Florence with Locals - Tour Guide Management System

A production-grade web application for managing tour guides, booking synchronization, payment tracking, and museum ticket inventory for a tour operator in Florence, Italy. Integrates with the Bokun API to pull bookings from OTA channels (Viator, GetYourGuide) and direct sales.

**Production**: https://withlocals.deetech.cc | **Status**: Fully Operational

## Technology Stack

| Layer | Technologies |
|-------|-------------|
| **Frontend** | React 18.2, Vite 5.1, TailwindCSS 3.4 (custom Tuscan theme) |
| **Backend** | PHP 8.2 REST API |
| **Database** | MySQL (40+ column tours table, 10+ tables) |
| **Integration** | Bokun REST API (HMAC-SHA1 auth) — Viator, GetYourGuide, direct sales |
| **PDF Reports** | jsPDF + jsPDF-AutoTable |
| **Error Monitoring** | Sentry.io (frontend + backend) |
| **CI/CD** | GitHub Actions (build + deploy + health checks) |
| **Hosting** | Hostinger (shared hosting, HTTPS) |
| **Testing** | Vitest + React Testing Library (52 tests) |

## Key Features

### Tour & Booking Management
- Full CRUD for tours with 40+ data fields per booking
- Bokun API integration: auto-sync every 15 minutes from Viator, GetYourGuide, and direct sales
- Multi-page pagination (200 bookings/page, up to 2,000 per sync)
- Cancelled booking detection with visual indicators
- Rescheduling tracking with audit trail
- Multi-channel language detection (English, Italian, etc.)

### Tour Grouping System (Feb 2026)
- Auto-groups same-departure bookings by product + date + time
- Max 9 PAX per group (Uffizi museum rule)
- Manual merge via drag-and-drop (HTML5 DnD)
- Guide assignment propagates to all tours in a group
- Database transactions + advisory locks for consistency
- Orphan group cleanup after every sync

### GYG Participant Names (Feb 2026)
- Extracts traveler names from GetYourGuide special requests
- Regex parsing: first/last name per traveler
- Expandable display: "First Last +N more"
- Copy-all-names button in booking details modal
- Backfill support for existing bookings

### Payment System
- Group-aware payments: 1 group = 1 guide payment (not per-booking)
- Duplicate payment prevention (HTTP 409 with force-override options)
- Per-guide summaries with cash/bank breakdown
- Date-range reports (summary, detailed, monthly, export)
- 4 PDF report types with Tuscan-themed design
- Europe/Rome timezone-aware calculations

### Guide Management
- Multi-language support, pagination (20/page)
- RESTful API with PUT for updates
- Per-operation loading states
- Database indexes for performance

### Museum Ticket Inventory
- Accademia ticket tracking with 15-minute time slots (08:15-17:30)
- Priority Tickets page for museum entrance bookings
- Smart ticket/tour detection via keyword filtering

### Mobile Responsive UI (Feb 2026)
- Dedicated mobile components: `TourCardMobile`, `TourGroupCardMobile`
- Touch-optimized (44px+ touch targets, WCAG compliant)
- Desktop: collapsible sidebar + data tables
- Mobile: hamburger menu + card-based layouts
- Selection mode for batch merge/assign on mobile

### Security & Infrastructure
- bcrypt password hashing, 24-hour session tokens
- Database-backed rate limiting (login: 5/min, read: 100/min, write: 30/min)
- SQL injection prevention (prepared statements throughout)
- AES-256-CBC encryption for Bokun API credentials
- CORS, HTTPS (HSTS), CSP, XSS protection headers
- Input validation (Validator class)
- Sentry.io error tracking (100% trace, 10% session replay)
- Gzip compression (70-80% response size reduction)
- Structured file logging with rotation

## Project Structure

```
guide-florence-with-locals/
├── src/                              # React frontend
│   ├── components/
│   │   ├── Layout/ModernLayout.jsx   # Responsive sidebar + mobile header
│   │   ├── UI/                       # Button, Card, Input, StatusBadge
│   │   ├── BookingDetailsModal.jsx   # Full booking details (6 sections)
│   │   ├── TourGroup.jsx            # Expandable group with DnD
│   │   ├── TourCardMobile.jsx       # Mobile tour card
│   │   ├── TourGroupCardMobile.jsx  # Mobile group card
│   │   ├── BokunAutoSyncProvider.jsx # 15-min auto-sync provider
│   │   └── Dashboard.jsx            # Stats overview
│   ├── pages/
│   │   ├── Tours.jsx                # Tour management + grouping + DnD
│   │   ├── Payments.jsx             # 4-tab payment workflow + PDF reports
│   │   ├── Guides.jsx               # Guide CRUD with pagination
│   │   ├── Tickets.jsx              # Museum ticket inventory
│   │   ├── PriorityTickets.jsx      # Ticket bookings display
│   │   ├── BokunIntegration.jsx     # Sync management (admin)
│   │   └── Login.jsx                # Authentication
│   ├── contexts/                    # AuthContext, PageTitleContext
│   ├── services/
│   │   ├── mysqlDB.js               # API layer with 1-min cache
│   │   ├── bokunAutoSync.js         # Background sync service
│   │   └── ticketsService.js        # Tickets CRUD
│   ├── hooks/useBokunAutoSync.jsx   # 15-min sync hook
│   └── utils/
│       ├── tourFilters.js           # Ticket vs tour detection
│       └── pdfGenerator.js          # 4 PDF report types
├── public_html/api/                 # PHP REST API
│   ├── config.php                   # DB, CORS, gzip, Sentry
│   ├── auth.php                     # Login/logout/verify
│   ├── tours.php                    # Tour CRUD + filters
│   ├── guides.php                   # Guide CRUD + pagination
│   ├── tour-groups.php              # Grouping: auto/merge/unmerge/dissolve
│   ├── payments.php                 # Payment transactions
│   ├── guide-payments.php           # Per-guide summaries
│   ├── payment-reports.php          # Date-range reports
│   ├── tickets.php                  # Ticket inventory
│   ├── BokunAPI.php                 # Bokun client (HMAC-SHA1)
│   ├── bokun_sync.php               # Sync orchestrator
│   ├── RateLimiter.php              # DB-backed rate limiting
│   ├── Validator.php                # Input validation
│   ├── Encryption.php               # AES-256-CBC
│   ├── Logger.php                   # File logging with rotation
│   └── SentryLogger.php            # Sentry.io integration
├── database/migrations/             # SQL migration files
├── scripts/                         # deploy.sh, deploy.ps1
├── docs/                            # 14 documentation files
├── .github/workflows/               # CI/CD (build + deploy + health check)
└── CLAUDE.md                        # AI-assisted development instructions
```

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth.php` | Login (bcrypt, 24h token) |
| GET | `/api/auth.php?action=verify` | Verify Bearer token |

### Tours
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tours.php` | List tours (filters: date, guide_id, upcoming, past) |
| GET | `/api/tours.php/{id}` | Get single tour |
| POST | `/api/tours.php` | Create tour |
| PUT | `/api/tours.php/{id}` | Update tour |
| DELETE | `/api/tours.php/{id}` | Delete tour |

### Tour Groups
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tour-groups.php` | List groups |
| POST | `/api/tour-groups.php?action=auto-group` | Auto-group by product+date+time |
| POST | `/api/tour-groups.php?action=manual-merge` | Merge specific tours |
| POST | `/api/tour-groups.php?action=unmerge` | Remove tour from group |
| PUT | `/api/tour-groups.php/{id}` | Update group (guide, name, notes) |
| DELETE | `/api/tour-groups.php/{id}` | Dissolve group |

### Guides
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/guides.php?page=1&per_page=20` | List guides with pagination |
| POST | `/api/guides.php` | Create guide |
| PUT | `/api/guides.php/{id}` | Update guide |
| DELETE | `/api/guides.php/{id}` | Delete guide |

### Payments
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/payments.php?tour_id=X` | Get payments for tour |
| POST | `/api/payments.php` | Create payment (group-aware dedup) |
| GET | `/api/guide-payments.php` | Per-guide summaries |
| GET | `/api/guide-payments.php?action=pending_tours` | Unpaid tours (group-aware) |
| GET | `/api/payment-reports.php?type=summary` | Summary/detailed/monthly/export |

### Bokun Integration
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/bokun_sync.php?action=sync` | Sync bookings for date range |
| POST | `/api/bokun_sync.php?action=full-sync` | Full year sync |
| POST | `/api/bokun_sync.php?action=backfill-names` | Backfill GYG participant names |

### Tickets
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tickets.php` | List tickets |
| POST | `/api/tickets.php` | Create ticket |
| PUT | `/api/tickets.php?id=X` | Update ticket |
| DELETE | `/api/tickets.php?id=X` | Delete ticket |

## Database Schema

| Table | Purpose |
|-------|---------|
| `tours` | 40+ columns: bookings, customer data, Bokun data, payment status, grouping |
| `tour_groups` | Departure grouping (max 9 PAX), guide propagation, manual merge flag |
| `guides` | Guide profiles with multi-language support |
| `payments` | Financial transactions (cash, bank, card, PayPal) |
| `tickets` | Museum ticket inventory (Accademia) |
| `users` / `sessions` | Authentication (bcrypt, 24h tokens) |
| `bokun_config` | Encrypted API credentials |
| `rate_limits` | IP-based rate limiting (auto-created) |
| `sync_logs` | Bokun sync history and diagnostics |

## Installation

### Prerequisites
- Node.js 18+ and npm
- PHP 8.2+ with curl, openssl, mysqli extensions
- MySQL database

### Setup

```bash
# Clone and install
git clone https://github.com/DhaNu1204/guide-florence-with-locals.git
cd guide-florence-with-locals/guide-florence-with-locals
npm install

# Configure environment
cp .env.example .env
# Edit .env with your database credentials and API keys

# Start development servers
npm run dev                          # Frontend on port 5173
php -S localhost:8080 -t public_html # Backend on port 8080
```

### Scripts

```bash
npm run dev          # Vite dev server (port 5173)
npm run build        # Production build to dist/
npm run test         # Run tests (watch mode)
npm run test:run     # Single test run
npm run test:coverage # Coverage report
```

## CI/CD Pipeline

GitHub Actions workflow on push to `main`:
1. **Build**: npm ci + vite build + PHP syntax validation
2. **Deploy**: SCP backend + rsync frontend to Hostinger
3. **Health Check**: Verify tours API, guides API, and frontend respond HTTP 200

Manual workflow dispatch supports selective frontend/backend deployment.

## Deployment

### Automated
Push to `main` triggers GitHub Actions deployment with health checks.

### Manual
```bash
# Build
npm run build

# Deploy backend
scp -P 65002 public_html/api/*.php u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/

# Deploy frontend
scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/

# Or use deployment scripts
./scripts/deploy.sh              # Full deploy
./scripts/deploy.sh --frontend   # Frontend only
./scripts/deploy.sh --backend    # Backend only
```

## Documentation

Detailed docs in `docs/` directory:
- [Environment Setup](docs/ENVIRONMENT_SETUP.md) - Database, credentials, servers
- [API Documentation](docs/API_DOCUMENTATION.md) - All endpoints and auth
- [Architecture](docs/ARCHITECTURE.md) - System design and data flow
- [Bokun Integration](docs/BOKUN_INTEGRATION.md) - API integration details
- [Development Guide](docs/DEVELOPMENT_GUIDE.md) - Commands, testing
- [Changelog](docs/CHANGELOG.md) - Full change history
- [Testing](docs/TESTING.md) - Test suite and coverage
- [Performance](docs/PERFORMANCE.md) - Optimization and security
- [Troubleshooting](docs/TROUBLESHOOTING.md) - Common issues

## License

Proprietary software for Florence with Locals.

## Authors

Florence with Locals Development Team
