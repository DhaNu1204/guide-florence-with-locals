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
**Last Critical Update**: October 26, 2025 - Tour date bug fixed, Bokun sync optimized, Payments page fixed (database views + table name correction)

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
18. **✅ Priority Tickets Enhancements**: Default to today's date, morning bookings first, click-to-view details

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
   - Login: dhanu / Kandy@123

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
   - ✅ Priority Tickets default date filter (shows all dates now)
   - ✅ Dashboard chronological sorting (date + time combined)
   - ✅ Tours CRUD operations (guide assignment & notes persistence)
   - ✅ Authentication session management working

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
- **Admin Login**: dhanu / Kandy@123

### Active Features
- ✅ Bokun API Integration (47+ bookings synced)
- ✅ Multi-language guide management
- ✅ Payment tracking with Italian timezone
- ✅ Museum ticket inventory (Uffizi/Accademia)
- ✅ Booking details modal (6 sections)
- ✅ Cancelled booking sync with visual indicators
- ✅ Rescheduling detection with audit trail

## Current Status

✅ **FULLY OPERATIONAL** - Production site live at https://withlocals.deetech.cc

- All core functionality working perfectly
- Database schema synchronized (Oct 25, 2025)
- Bokun integration operational with 47+ bookings
- Complete payment tracking system
- Modern responsive UI across all devices

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

## Support

For troubleshooting and common issues, see [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

## Repository

**GitHub**: https://github.com/DhaNu1204/guide-florence-with-locals.git

---

**Last Updated**: October 25, 2025
**Production URL**: https://withlocals.deetech.cc
**Status**: ✅ Fully Operational

**📌 Remember**: When working on this project, always read the relevant documentation files in `docs/` folder to understand the complete context before making changes.
