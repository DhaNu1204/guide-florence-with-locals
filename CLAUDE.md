# Florence with Locals Tour Guide Management System

> **📋 CLAUDE CODE INSTRUCTIONS**: This is the main project documentation file. Read this file every time you start working on this project to understand the complete context, architecture, and recent changes.
>
> **When you need detailed information**, read the specific documentation files in the `docs/` folder:
> - For environment/setup questions → Read `docs/ENVIRONMENT_SETUP.md`
> - For recent changes/history → Read `docs/CHANGELOG.md`
> - For API information → Read `docs/API_DOCUMENTATION.md`
> - For development commands → Read `docs/DEVELOPMENT_GUIDE.md`
> - For troubleshooting → Read `docs/TROUBLESHOOTING.md`
> - For architecture details → Read `docs/ARCHITECTURE.md`
> - For Bokun integration → Read `docs/BOKUN_INTEGRATION.md`

## Project Overview

A comprehensive tour guide management system for Florence, Italy that integrates with Bokun API for automatic booking synchronization and guide assignment. Features a modern, responsive UI with complete CRUD operations and role-based access control.

**Production Status**: ✅ FULLY OPERATIONAL at https://withlocals.deetech.cc
**Last Critical Update**: January 25, 2026 - GetYourGuide booking sync fixed, timezone correction, 200+ bookings now syncing

## Tech Stack

- **Frontend**: React 18 + Vite + TailwindCSS
- **Backend**: PHP 8.2
- **Database**: MySQL
- **API Integration**: Bokun REST API with HMAC-SHA1 authentication
- **Development**: Local environment on Windows
- **UI Components**: Custom modern component system with responsive design

## Key Features

1. **Tour Management**: Create, edit, and manage tours with comprehensive details
2. **Guide Management**: Manage tour guides with multi-language support and email contacts
3. **Payment System**: Complete payment tracking with guide-wise analytics, date range reports, and Italian timezone support
4. **Bokun Integration**: Dedicated page for automatic sync of bookings from Bokun (separated from tours)
5. **Authentication**: Role-based access control (admin/viewer)
6. **Ticket Management**: Museum entrance ticket inventory system for Uffizi and Accademia
7. **Priority Tickets**: Dedicated page for museum ticket bookings with booking details modal
8. **Modern UI/UX**: 100% mobile responsive with sidebar navigation and compact card layouts
9. **Real-time Updates**: Live data synchronization with fallback localStorage support
10. **✅ Cancelled Booking Sync**: Automatic synchronization of cancelled bookings with red visual indicators
11. **✅ Rescheduling Support**: Complete detection and tracking of rescheduled tours with audit trail
12. **✅ Cache Management**: Force refresh functionality to ensure latest data display
13. **✅ Multi-Channel Language Detection**: Automatic tour language extraction from Bokun API (Viator, GetYourGuide, and other booking channels)
14. **✅ Smart Payment Status**: Intelligent payment tracking distinguishing between customer platform payments and guide payments
15. **✅ Ticket Product Filtering**: Automatic exclusion of museum entrance tickets from tour management views
16. **✅ Booking Details Modal**: Comprehensive modal showing all booking information with 6 detailed sections (Oct 24, 2025)
17. **✅ Participant Breakdown**: Adults/children display extracted from Bokun API (INFANT excluded)
18. **✅ Priority Tickets Enhancements**: Upcoming filter (today + 60 days), morning bookings first, click-to-view details
19. **✅ Automatic Bokun Sync**: Background sync every 15 minutes, on startup, and on app focus (Jan 25, 2026)
20. **✅ Enhanced Ticket Detection**: Added "Entrance Ticket" keyword for better Uffizi ticket detection
21. **✅ GetYourGuide Booking Sync**: Full support for GetYourGuide OTA bookings via SUPPLIER role (Jan 25, 2026)
22. **✅ Timezone Fix**: Using startTimeStr for accurate local tour times instead of UTC conversion
23. **✅ Pagination Enhancement**: Increased Bokun API pageSize to 200 with multi-page support (200+ bookings)
24. **✅ Guides Page Improvements**: Pagination, PUT for updates, per-operation loading states, database indexes (Jan 28, 2026)

## Project Structure

```
guide-florence-with-locals/
├── src/                           # React frontend source
│   ├── components/               # React components
│   │   ├── Layout/              # Modern responsive layout components
│   │   │   └── ModernLayout.jsx # Main layout with sidebar navigation
│   │   ├── UI/                  # Reusable UI components
│   │   │   ├── Card.jsx         # Modern card component
│   │   │   ├── Button.jsx       # Styled button component
│   │   │   └── Input.jsx        # Form input components
│   │   ├── BookingDetailsModal.jsx # Comprehensive booking details modal (NEW - Oct 24, 2025)
│   │   ├── TourCards.jsx        # Tour display component (compact horizontal layout)
│   │   ├── CardView.jsx         # Tour card container
│   │   ├── PaymentRecordForm.jsx # Payment recording form with Italian timezone
│   │   └── Dashboard.jsx        # Statistics dashboard
│   ├── pages/                   # Page components
│   │   ├── Tours.jsx           # Tour management with booking details modal
│   │   ├── Guides.jsx          # Guide management with multi-language support
│   │   ├── Payments.jsx        # Payment tracking with calendar date filtering
│   │   ├── Tickets.jsx         # Museum ticket inventory management
│   │   ├── PriorityTickets.jsx # Museum ticket bookings with modal and participant breakdown
│   │   ├── EditTour.jsx        # Tour editing interface
│   │   ├── BokunIntegration.jsx # Dedicated Bokun integration page
│   │   └── Login.jsx           # Authentication
│   ├── contexts/               # React contexts
│   │   ├── AuthContext.jsx     # Authentication management
│   │   └── PageTitleContext.jsx # Dynamic page titles
│   └── services/               # API services
│       └── mysqlDB.js          # Database service layer with caching
├── public_html/                # PHP backend
│   └── api/                    # API endpoints
│       ├── config.php          # Database configuration with CORS
│       ├── tours.php           # Tours CRUD API
│       ├── guides.php          # Guides CRUD API
│       ├── payments.php        # Payment transactions CRUD API
│       ├── guide-payments.php  # Guide payment summaries and analytics
│       ├── payment-reports.php # Payment reports with date filtering
│       ├── tickets.php         # Tickets CRUD API
│       ├── BokunAPI.php        # Bokun API service class
│       ├── bokun_sync.php      # Bokun sync endpoints
│       ├── auth.php            # Authentication API
│       └── database_check.php  # Database verification utility
├── database/                   # Database schemas and setup
└── docs/                       # Documentation (organized by topic)
    ├── ENVIRONMENT_SETUP.md    # Environment, database, servers
    ├── CHANGELOG.md            # All recent updates and changes
    ├── API_DOCUMENTATION.md    # API endpoints and authentication
    ├── DEVELOPMENT_GUIDE.md    # Development commands and testing
    ├── BOKUN_INTEGRATION.md    # Bokun API integration details
    ├── ARCHITECTURE.md         # System design and data flow
    ├── FEATURES.md             # Feature status and roadmap
    ├── PERFORMANCE.md          # Optimization and security
    ├── TESTING.md              # QA and test coverage
    ├── TROUBLESHOOTING.md      # Common issues and solutions
    └── PROJECT_STATUS.md       # Completion phases and status
```

## Documentation Index

All detailed documentation has been organized into topic-specific files in the `docs/` directory:

### Quick Reference
- 🚀 **[Getting Started](docs/ENVIRONMENT_SETUP.md)** - Environment setup, database configuration, access credentials
- 💻 **[Development](docs/DEVELOPMENT_GUIDE.md)** - Development commands, port management, testing
- 📝 **[Changelog](docs/CHANGELOG.md)** - Recent updates and changes (Oct 2025 - Aug 2025)
- 🔌 **[API Documentation](docs/API_DOCUMENTATION.md)** - API endpoints and authentication

### Technical Documentation
- 🏗️ **[Architecture](docs/ARCHITECTURE.md)** - System design, component structure, data flow
- ⚡ **[Performance & Security](docs/PERFORMANCE.md)** - Optimization strategies and security features
- 🧪 **[Testing](docs/TESTING.md)** - QA processes and test coverage

### Feature Documentation
- ✨ **[Features](docs/FEATURES.md)** - Complete feature list and status
- 🔗 **[Bokun Integration](docs/BOKUN_INTEGRATION.md)** - Bokun API integration details and status

### Operations
- 📊 **[Project Status](docs/PROJECT_STATUS.md)** - Current status and completion phases
- 🛠️ **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Common issues and solutions

## Quick Start

### Prerequisites
- PHP 8.2+ with curl, openssl, mysqli extensions
- MySQL database
- Node.js 16+ and npm

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/DhaNu1204/guide-florence-with-locals.git
   cd guide-florence-with-locals
   ```

2. **Install dependencies**
   ```bash
   npm install
   ```

3. **Configure database**
   - Update `public_html/api/config.php` with your database credentials
   - Import database schema from `database/` directory

4. **Start development servers**
   ```bash
   # Terminal 1 - Frontend (port 5173)
   npm run dev

   # Terminal 2 - Backend (port 8080)
   cd public_html
   php -S localhost:8080
   ```

5. **Access the application**
   - Frontend: http://localhost:5173
   - Login: dhanu / ***REMOVED***

For detailed setup instructions, see [ENVIRONMENT_SETUP.md](docs/ENVIRONMENT_SETUP.md)

## 🔴 CRITICAL INFORMATION - READ FIRST

### Development Rules
1. **PORT MANAGEMENT**: ALWAYS use port 5173 for frontend - NEVER allow different ports
   - Kill existing processes before starting: `netstat -ano | findstr :5173` then `taskkill //PID [PID] //F`
   - Backend uses port 8080

2. **Database**: Production database has 40 columns in `tours` table - synchronized Oct 25, 2025
   - Important columns: `language`, `rescheduled`, `original_date`, `original_time`, `notes`, `bokun_data`
   - Sessions table has `token` column (fixed Oct 25, 2025)

3. **Recent Critical Fixes** (Read `docs/CHANGELOG.md` for full details):
   - ✅ **Guides page improvements** - Pagination, PUT for updates, per-operation loading, database indexes (Jan 28, 2026)
   - ✅ **GetYourGuide sync fixed** - Changed Bokun API to use both SUPPLIER and SELLER roles (Jan 25, 2026)
   - ✅ **Timezone fix** - Using startTimeStr for accurate local times (was showing +1 hour offset)
   - ✅ **Pagination fix** - Increased pageSize from 50 to 200, added multi-page support (200+ bookings)
   - ✅ **Tours page fix** - Default to upcoming filter, increased toursPerPage to 100
   - ✅ Priority Tickets API fix (per_page max increased to 500, upcoming filter added)
   - ✅ Dashboard fix - Added upcoming filter to show 2026 data
   - ✅ Enhanced ticket detection ("Entrance Ticket" keyword added)

### Key File Locations
- **Frontend Entry**: `src/main.jsx`
- **Main Layout**: `src/components/Layout/ModernLayout.jsx`
- **Database Config**: `public_html/api/config.php`
- **API Endpoints**: `public_html/api/*.php`
- **Service Layer**: `src/services/mysqlDB.js` (handles caching)

### Environment Details
- **Development**: http://localhost:5173 (frontend) + http://localhost:8080 (backend)
- **Production**: https://withlocals.deetech.cc
- **Database**: florence_guides (dev) / u803853690_florence_guides (prod)
- **SSH Access**: ssh -p 65002 u803853690@82.25.82.111
- **Admin Login**: dhanu / ***REMOVED***

### Active Features
- ✅ Bokun API Integration (200+ bookings synced from Viator AND GetYourGuide)
- ✅ Automatic Bokun sync every 15 minutes
- ✅ Multi-channel OTA support (Viator, GetYourGuide, direct bookings)
- ✅ Multi-language guide management
- ✅ Payment tracking with Italian timezone
- ✅ Museum ticket inventory (Uffizi/Accademia)
- ✅ Priority Tickets with upcoming filter
- ✅ Booking details modal (6 sections)
- ✅ Cancelled booking sync with visual indicators
- ✅ Rescheduling detection with audit trail

## Current Status

✅ **FULLY OPERATIONAL** - Production site live at https://withlocals.deetech.cc

- All core functionality working perfectly
- Database schema synchronized (Oct 25, 2025)
- Bokun integration operational with 200+ bookings (Viator + GetYourGuide)
- Complete payment tracking system
- Modern responsive UI across all devices
- Correct timezone handling for tour times

For detailed status information, see [PROJECT_STATUS.md](docs/PROJECT_STATUS.md)

## 💡 Working on This Project

When the user asks you to work on this project:

1. **First Time / Starting Session**:
   - Read this CLAUDE.md file to understand the project
   - Read `docs/CHANGELOG.md` to see recent changes
   - Read relevant docs based on the task (e.g., `docs/API_DOCUMENTATION.md` for API work)

2. **Before Making Changes**:
   - Check `docs/ARCHITECTURE.md` to understand system design
   - Check `docs/TROUBLESHOOTING.md` for known issues
   - Verify database schema matches documentation (40 columns in tours table)

3. **Development Workflow**:
   - Frontend changes: Edit files in `src/`
   - Backend changes: Edit files in `public_html/api/`
   - Test locally before suggesting production deployment
   - Always use port 5173 for frontend, 8080 for backend

4. **When Stuck**:
   - Read `docs/TROUBLESHOOTING.md` for common issues
   - Check `docs/DEVELOPMENT_GUIDE.md` for commands
   - Read `docs/PROJECT_STATUS.md` for overall status

## 🚀 Deployment Process

### Quick Deploy Command
Ask Claude Code to: *"Create a special agent to do the deployment process step by step"*

### Manual Deployment Steps

1. **Build Frontend**
   ```bash
   cd guide-florence-with-locals
   npm run build
   ```

2. **Deploy Backend (PHP files)**
   ```bash
   scp -P 65002 public_html/api/*.php u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/api/
   ```

3. **Deploy Frontend**
   ```bash
   scp -P 65002 -r dist/* u803853690@82.25.82.111:/home/u803853690/domains/deetech.cc/public_html/withlocals/
   ```

4. **Verify Deployment**
   ```bash
   curl -s "https://withlocals.deetech.cc/api/tours.php?upcoming=true&per_page=10"
   ```

### Production Server Details
| Property | Value |
|----------|-------|
| SSH Host | 82.25.82.111 |
| SSH Port | 65002 |
| SSH User | u803853690 |
| Web Root | /home/u803853690/domains/deetech.cc/public_html/withlocals |
| URL | https://withlocals.deetech.cc |

## 🏗️ Architecture Overview

### Auto-Sync System
```
src/
├── components/BokunAutoSyncProvider.jsx  # Provider wrapper + status indicator
├── hooks/useBokunAutoSync.jsx            # React hook for sync state
└── services/bokunAutoSync.js             # Sync service class
```

**Sync Triggers:**
- On app startup (if last sync > 15 minutes ago)
- Every 15 minutes (periodic interval)
- On app focus/visibility change

### API Utilities (NEW - Jan 2026)
```
public_html/api/
├── BaseAPI.php      # Base class for consistent API responses
├── EnvLoader.php    # Environment variable loader
├── Logger.php       # Centralized error logging
└── Validator.php    # Input validation helper
```

### Ticket Detection Keywords
Located in `src/utils/tourFilters.js`:
- "Entry Ticket"
- "Entrance Ticket" (added Jan 2026)
- "Priority Ticket"
- "Skip the Line"
- "Skip-the-Line"

### API Pagination
- **Default**: 50 records per page (20 for guides)
- **Maximum**: 500 records per page (increased Jan 2026)
- **Upcoming Filter**: `?upcoming=true` returns today + 60 days

### Guides API (Updated Jan 28, 2026)
Located in `public_html/api/guides.php`:

**Endpoints:**
- `GET /guides.php?page=1&per_page=20` - List guides with pagination
- `POST /guides.php` - Create new guide
- `PUT /guides.php/{id}` - Update existing guide
- `DELETE /guides.php/{id}` - Delete guide

**Response Format (GET):**
```json
{
  "data": [...],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total": 45,
    "total_pages": 3,
    "has_next": true,
    "has_prev": false
  }
}
```

**Database Indexes:**
- `idx_email` - Index on email column
- `idx_name` - Index on name column

### Bokun API Integration Details
Located in `public_html/api/BokunAPI.php`:

**Booking Roles** (CRITICAL for OTA sync):
- `SUPPLIER` - Fetches OTA bookings (Viator, GetYourGuide) where you supply the tour
- `SELLER` - Fetches direct bookings where you sell directly
- **Both roles are queried** to get all bookings

**Time Extraction** (CRITICAL for correct times):
- Uses `startTimeStr` field (local time) instead of UTC timestamp conversion
- This ensures tour times match what's shown in Bokun/OTA dashboards

**Pagination**:
- Default pageSize: 200 (increased from 50)
- Multi-page support: Fetches up to 10 pages (2000 bookings max)
- Deduplicates bookings by ID across roles

### API Rate Limiting (NEW - Jan 29, 2026)
Located in `public_html/api/RateLimiter.php`:

**Rate Limits by Endpoint Type:**
| Type | Limit | Window | Used By |
|------|-------|--------|---------|
| login | 5 | 1 min | auth.php |
| read | 100 | 1 min | GET requests |
| write/create | 30 | 1 min | POST requests |
| update | 30 | 1 min | PUT requests |
| delete | 10 | 1 min | DELETE requests |
| bokun_sync | 10 | 1 min | bokun_sync.php |
| webhook | 30 | 1 min | bokun_webhook.php |

**Usage in Endpoints:**
```php
// Automatic rate limiting by HTTP method
autoRateLimit('endpoint-name');

// Explicit rate limit type
applyRateLimit('login');           // 5 per minute
applyRateLimit('read');            // 100 per minute
applyRateLimit('bokun_sync');      // 10 per minute

// Custom limits
applyRateLimit('custom', 50, 60);  // 50 per 60 seconds
```

**HTTP Response Headers:**
- `X-RateLimit-Limit` - Max requests allowed
- `X-RateLimit-Remaining` - Requests remaining
- `X-RateLimit-Reset` - Unix timestamp when limit resets
- `Retry-After` - Seconds until retry (on 429 response)

**Database Table:** `rate_limits` (auto-created if missing)

## 🔮 Future Development Roadmap

### Planned Features
| Feature | Priority | Status |
|---------|----------|--------|
| Push notifications for new bookings | High | Planned |
| Guide availability calendar | High | Planned |
| Advanced reporting & analytics | Medium | Planned |
| Customer communication portal | Medium | Planned |
| Mobile app (React Native) | Low | Future |
| Multi-location support | Low | Future |

### Technical Improvements
| Improvement | Priority | Description |
|-------------|----------|-------------|
| ~~Database indexing~~ | ~~High~~ | ✅ Done - Added indexes for guides table (Jan 28, 2026) |
| ~~API rate limiting~~ | ~~Medium~~ | ✅ Done - Database-backed rate limiting (Jan 29, 2026) |
| ~~Unit tests~~ | ~~Medium~~ | ✅ Done - Vitest + PHP tests, 52 tests passing (Jan 29, 2026) |
| Caching layer (Redis) | Medium | Reduce database load |
| CI/CD pipeline | Low | GitHub Actions for automated deployment |
| TypeScript migration | Low | Type safety for frontend |

### Known Issues to Address
1. **Large dataset pagination** - Consider infinite scroll for 500+ records
2. **Offline support** - Service worker for offline viewing
3. **Real-time updates** - WebSocket for live booking notifications
4. **Multi-tenant support** - Support for multiple tour companies

### Resolved Issues (Jan 2026)
1. **GetYourGuide bookings not syncing** - Fixed by adding SUPPLIER role to Bokun API (Jan 25)
2. **Tour times off by 1 hour** - Fixed by using startTimeStr instead of UTC timestamp (Jan 25)
3. **Only 50 bookings syncing** - Fixed by increasing pageSize to 200 with pagination (Jan 25)
4. **Tours page showing old data** - Fixed by defaulting to upcoming filter (Jan 25)
5. **Guides page missing pagination** - Added pagination with 20 per page (Jan 28)
6. **Guides API using POST for updates** - Changed to RESTful PUT `/guides.php/{id}` (Jan 28)
7. **Guides page global loading state** - Added per-operation loading for Save/Delete buttons (Jan 28)
8. **Guides table missing indexes** - Added `idx_email` and `idx_name` indexes (Jan 28)

## 📁 New Files Added (Jan 2026)

### Backend Utilities
| File | Purpose |
|------|---------|
| `BaseAPI.php` | Standardized JSON responses, error handling |
| `EnvLoader.php` | Load environment variables from .env files |
| `Logger.php` | File-based error logging with rotation |
| `Validator.php` | Input sanitization and validation |
| `RateLimiter.php` | Database-backed API rate limiting - Jan 29, 2026 |

### Database Migrations
| File | Purpose |
|------|---------|
| `database/add_missing_indexes.sql` | Performance optimization |
| `database/migrations/create_sync_logs_table.sql` | Sync history tracking |
| `database/add_guides_indexes.sql` | Guides table indexes (email, name) - Jan 28, 2026 |
| `database/migrations/create_rate_limits_table.sql` | API rate limiting storage - Jan 29, 2026 |

### Configuration
| File | Purpose |
|------|---------|
| `.env.example` | Template for environment configuration |

## 🔧 Common Development Tasks

### Add New Ticket Detection Keyword
Edit `src/utils/tourFilters.js`:
```javascript
const TICKET_KEYWORDS = [
  'Entry Ticket',
  'Entrance Ticket',
  'Priority Ticket',
  'Skip the Line',
  'Skip-the-Line',
  'YOUR_NEW_KEYWORD'  // Add here
];
```

### Extend Auto-Sync Interval
Edit `src/hooks/useBokunAutoSync.jsx`:
```javascript
const SYNC_INTERVAL_MS = 15 * 60 * 1000; // Change 15 to desired minutes
```

### Add New API Endpoint
1. Create `public_html/api/your-endpoint.php`
2. Use BaseAPI pattern:
```php
<?php
require_once 'config.php';
require_once 'BaseAPI.php';

$api = new BaseAPI();
// Your logic here
$api->sendSuccess($data);
```

### Database Schema Changes
1. Update local database first
2. Test thoroughly
3. Create migration SQL in `database/migrations/`
4. Apply to production via SSH

## 🛠️ Florence Skills Toolkit

Custom development skills are available in `../florence-skills/` directory. These provide specialized guidance for this project:

### Available Skills
| Skill | Path | Use For |
|-------|------|---------|
| **UI Designer** | `skills/ui-designer/SKILL.md` | Tuscan-inspired components, colors, styling |
| **Mobile Optimizer** | `skills/mobile-optimizer/SKILL.md` | Responsive design, touch interactions |
| **Security Hardener** | `skills/security-hardener/SKILL.md` | Input validation, XSS/SQL injection prevention |
| **Reliability Engineer** | `skills/reliability-engineer/SKILL.md` | Error handling, monitoring, deployment |
| **PHP Backend** | `skills/php-backend/SKILL.md` | API endpoints, database patterns |
| **React Patterns** | `skills/react-patterns/SKILL.md` | Component structure, hooks, contexts |

### Available Agents
| Agent | Path | Use For |
|-------|------|---------|
| **Code Reviewer** | `agents/code-reviewer/AGENT.md` | Security/performance code review |
| **Deployment Checker** | `agents/deployment-checker/AGENT.md` | Pre-deployment validation |

### How to Use
Ask Claude Code to use a skill:
```
Using the UI Designer skill, create a new card component for tour details.
Using the PHP Backend skill, create an API endpoint for guide schedules.
Using the Code Reviewer agent, review my changes for security issues.
```

Claude will read the relevant SKILL.md file and apply its patterns to your request.

## Support

For troubleshooting and common issues, see [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

## Repository

**GitHub**: https://github.com/DhaNu1204/guide-florence-with-locals.git

---

**Last Updated**: January 28, 2026
**Production URL**: https://withlocals.deetech.cc
**Status**: ✅ Fully Operational
**Last Deployment**: January 25, 2026 02:00 UTC
**Bookings Synced**: 200+ (Viator + GetYourGuide)

**📌 Remember**: When working on this project, always read the relevant documentation files in `docs/` folder to understand the complete context before making changes.
