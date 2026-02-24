# Changelog - Recent Major Updates

## ✅ SECURITY HARDENING (2026-02-24)

### Comprehensive Security Audit & Fixes
✅ COMPLETED - Full security review and remediation across 4 severity levels

- **Scope**: 18 vulnerabilities identified (3 Critical, 9 High, 6 Medium, 3 Low), all fixed
- **Commits**: 4 commits (`b7beeba`, `5c91244`, `b5edc8b`, `a906629`)
- **Deployed**: All changes live on production, verified via smoke tests

### Critical & High Priority Fixes
- **Deleted 57 test/debug files** from repository — contained hardcoded credentials, database info, and debug endpoints (e.g., `test_password.php`, `debug_headers.php`, `check_db.php`)
- **Added `Middleware::requireAuth($conn)`** to all API endpoints (tours, guides, payments, tickets, tour-groups, guide-payments, payment-reports, bokun_sync, bokun_webhook)
- **Fixed auth bypass** in `bokun_sync.php` — `action=sync` path skipped authentication
- **Removed wildcard CORS** `Access-Control-Allow-Origin: *` from `tours.php`
- **Enabled SSL verification** in `BokunAPI.php` — was using `CURLOPT_SSL_VERIFYPEER => false`

### Medium Priority Fixes
- **Fixed token key mismatch** — `mysqlDB.js` axios interceptor read `authToken` but `AuthContext.jsx` stored `token` in localStorage (was breaking all authenticated API calls after auth enforcement)
- **Converted SQL interpolation to prepared statements** in `guide-payments.php` — 3 queries using `real_escape_string` + string interpolation replaced with `bind_param()`
- **Suppressed error message leaks** across 10 PHP files — `$conn->error`, `$stmt->error`, `$e->getMessage()` no longer exposed to clients; moved to `error_log()` only
- **Added session cleanup** — probabilistic (5% on login) deletion of expired sessions
- **Removed info leaks** — auth default response no longer exposes database name; config error responses no longer expose environment name

### Low Priority Fixes
- **Deleted 8 remaining debug files from production server** (not in git) — including `check_getyourguide.php` which had a hardcoded password
- **Added development CSP header** — was missing entirely for dev environment
- **Fixed `.htaccess` wildcard CORS** — Apache `Header always set Access-Control-Allow-Origin "*"` was overriding PHP's environment-aware origin checking; removed CORS from `.htaccess` entirely
- **Updated `.gitignore`** — added patterns for `*_test.php`, explicit entries for `compression_test.php`, `sentry_test.php`, `tests/run_tests.php`
- **Fixed dead routes** in `index.php` — removed references to deleted files (`update_paid_status.php`, `update_cancelled_status.php`), consolidated to `tours.php`
- **Fixed `404.php`** — removed `$_SERVER['REQUEST_URI']` from JSON response

### Smoke Test Results (Production)
All 7 tests passing:
| Test | Status |
|------|--------|
| Frontend loads (200) | PASS |
| Protected endpoints return 401 | PASS |
| Login returns valid token | PASS |
| Tours API with token | PASS |
| Tickets API with token | PASS |
| Bokun sync responds correctly | PASS |
| CORS not wildcard | PASS |

### Files Modified
- **Backend** (12 files): tours.php, guides.php, payments.php, tickets.php, tour-groups.php, guide-payments.php, payment-reports.php, bokun_sync.php, bokun_webhook.php, auth.php, config.php, BokunAPI.php
- **Frontend** (1 file): mysqlDB.js (token key fix)
- **Config** (3 files): .htaccess, index.php, 404.php, .gitignore
- **Deleted**: 57 test/debug/migration files from repository + 8 from production server

---

## ✅ UNASSIGNED TOURS REPORT (2026-02-23)

### Downloadable Unassigned Tours Report
- **Purpose**: Generate a plain-text report of unassigned tours (date, time, location) for sharing with guides
- **Frontend**: New "Unassigned Report" button in the Summary card at bottom of Tours page
  - Iterates existing `groupedTours` memo (respects all active filters: date, guide, upcoming/past/date range)
  - Excludes cancelled tours and ticket products (already filtered by groupedTours)
  - Output shows only **date, time, and location** — clean format for guides
  - Location extracted from tour title via keyword matching: Uffizi, Accademia, Duomo, Pitti, Boboli, Palazzo Vecchio, San Lorenzo, Santa Croce, Ponte Vecchio, Bargello, Vasari Corridor (defaults to "Florence")
  - Downloads as `unassigned_tours_YYYYMMDD_HHmm.txt` via Blob
  - Responsive: shows "Unassigned Report" on desktop, "Report" on mobile
- **No backend changes**: Entirely client-side using existing filtered data
- **File Modified**: `src/pages/Tours.jsx`

---

## ✅ CUSTOM DATE RANGE FILTERING & CACHE FIX (2026-02-23)

### Custom Date Range Filter
- **Backend**: Added `start_date` and `end_date` query parameters to `tours.php` GET handler
  - Generates `WHERE t.date >= ? AND t.date <= ?` with prepared statements
  - Both params validated with regex `/^\d{4}-\d{2}-\d{2}$/`
  - Takes priority over `past`, `upcoming`, and `date` filters
- **Service Layer**: Added `start_date`, `end_date`, and `past` filter passthrough in `mysqlDB.js`
- **Frontend**: New "Date Range" button in Tours.jsx filter bar
  - Dual date picker with start/end inputs and "to" separator
  - `end_date` input has `min` constraint to prevent invalid ranges
  - Skips fetching while range is incomplete (only one date selected)
  - All existing filter buttons properly clear date range mode when clicked
- **Cache Fix**: Added `past` and `start_date` to `hasFilters` check in `getTours()`
  - Previously, switching to Past 40 Days or Date Range returned stale cached data from Upcoming
- **Files Modified**: `tours.php`, `mysqlDB.js`, `Tours.jsx`

---

## ✅ PDF REPORT GENERATION & PAYMENT SYSTEM FIXES (2026-01-29)

### PDF Report Generation
✅ COMPLETED - Frontend-only PDF generation using jsPDF (no PHP dependencies)

- **Purpose**: Generate professional PDF reports for guide payments directly in browser
- **Tech Stack**: jsPDF 2.5.2 + jsPDF-AutoTable 3.8.4
- **Design**: Tuscan-themed branding with terracotta accent color (#C75D3A)

- **Report Types Available**:
  | Report | Description | Columns |
  |--------|-------------|---------|
  | Guide Payment Summary | Overview of all guides with payment totals | Guide, Tours, Paid, Unpaid, Total |
  | Pending Payments | Tours awaiting guide payment | Guide, Tour, Date, Participants |
  | Payment Transactions | Detailed payment history | Guide, Tour, Amount, Method, Date |
  | Monthly Summary | Monthly payment breakdown | Month, Total Paid, Cash, Bank Transfer |

- **Files Created**:
  - `src/utils/pdfGenerator.js` - Complete PDF generation utility with 4 report templates

- **Files Modified**:
  - `src/pages/Payments.jsx` - Added PDF download buttons for Guide Payments and Pending tabs

- **Usage**:
  ```javascript
  import { generateGuidePaymentSummaryPDF, generatePendingPaymentsPDF } from '../utils/pdfGenerator';

  // Generate and download PDF
  generateGuidePaymentSummaryPDF(guidesData);
  generatePendingPaymentsPDF(pendingToursData);
  ```

- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION

---

### Payment System Critical Bug Fixes
✅ COMPLETED - Fixed three critical bugs causing inconsistent payment tracking

- **Bug 1: Table Mismatch in VIEW**
  - **Issue**: `guide_payment_summary` VIEW queried `payment_transactions` table (empty/non-existent)
  - **Impact**: All guide payment totals showed €0.00
  - **Fix**: Updated VIEW to reference `payments` table
  - **File**: `database/migrations/fix_guide_payment_summary_view.sql`

- **Bug 2: Inconsistent "Unpaid Tour" Logic**
  - **Issue**: Three different definitions of "unpaid tour" across codebase
  - **Frontend**: Used legacy `tour.paid` field
  - **Backend**: Queried `tours.payment_status` field
  - **Correct**: Check actual `payments` table for records
  - **Fix**: Added `pending_tours` API endpoint with authoritative logic

- **Bug 3: Pending Tab False Positives**
  - **Issue**: Pending tab used legacy `paid` field instead of checking `payments` table
  - **Impact**: Showed incorrect pending counts, sometimes 0 when tours existed
  - **Fix**: Changed Payments.jsx to fetch from new API endpoint

- **Files Created**:
  - `database/migrations/fix_guide_payment_summary_view.sql` - VIEW fix SQL

- **Files Modified**:
  - `public_html/api/guide-payments.php` - Added `pending_tours` action endpoint
  - `src/pages/Payments.jsx` - Changed to use API for pending tours
  - `src/components/Dashboard.jsx` - Added API call for pending payments count

- **New API Endpoint**:
  ```
  GET /api/guide-payments.php?action=pending_tours

  Response:
  {
    "success": true,
    "count": 5,
    "data": [
      {
        "id": 123,
        "title": "Tour Name",
        "date": "2026-01-15",
        "time": "09:00",
        "guide_id": 1,
        "guide_name": "Guide Name",
        "participants": 4
      }
    ]
  }
  ```

- **Verification Queries**:
  ```sql
  -- Verify VIEW uses correct table
  SHOW CREATE VIEW guide_payment_summary;
  -- Should show: LEFT JOIN payments p ON t.id = p.tour_id

  -- Test pending tours
  SELECT COUNT(*) FROM tours t
  WHERE t.date < CURDATE()
    AND t.cancelled = 0
    AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.tour_id = t.id);
  ```

- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (January 29, 2026)

---

## ✅ AUTOMATED TESTING IMPLEMENTATION (2026-01-29)

### React Testing with Vitest
✅ COMPLETED - Comprehensive testing infrastructure for frontend

- **Testing Stack**:
  - Vitest (test runner, compatible with Vite)
  - @testing-library/react (React component testing)
  - @testing-library/jest-dom (DOM assertions)
  - @testing-library/user-event (user interaction simulation)
  - jsdom (DOM environment)

- **Test Commands**:
  ```bash
  npm test           # Run tests in watch mode
  npm run test:run   # Run tests once
  npm run test:coverage  # Run with coverage report
  ```

- **Test Coverage**:
  | Component | Tests | Description |
  |-----------|-------|-------------|
  | Button.jsx | 23 | Rendering, clicks, loading, disabled, icons, accessibility |
  | Login.jsx | 16 | Form rendering, input handling, validation, structure |
  | mysqlDB.js | 13 | API calls, error handling, caching, pagination |
  | **Total** | **52** | All passing |

- **Files Created**:
  - `vite.config.js` - Updated with test configuration
  - `src/test/setup.js` - Test environment setup
  - `src/components/__tests__/Button.test.jsx`
  - `src/pages/__tests__/Login.test.jsx`
  - `src/services/__tests__/mysqlDB.test.js`
  - `public_html/api/tests/run_tests.php` - PHP API test runner

- **PHP API Tests**:
  - Simple test runner (no PHPUnit needed)
  - Tests for auth, tours, guides endpoints
  - Rate limiting verification
  - Run with: `php public_html/api/tests/run_tests.php`

---

## ✅ API RATE LIMITING IMPLEMENTATION (2026-01-29)

### Database-Backed Rate Limiting
✅ COMPLETED - Comprehensive API rate limiting for all endpoints

- **Purpose**: Protect against brute force attacks, API abuse, and spam
- **Implementation**: Database-backed storage (Hostinger-compatible, no Redis)
- **Rate Limits Configured**:
  | Endpoint Type | Limit | Window |
  |---------------|-------|--------|
  | Login/Auth | 5 requests | per minute |
  | Read operations | 100 requests | per minute |
  | Write/Create | 30 requests | per minute |
  | Update | 30 requests | per minute |
  | Delete | 10 requests | per minute |
  | Bokun Sync | 10 requests | per minute |
  | Webhooks | 30 requests | per minute |

- **Files Created**:
  - `public_html/api/RateLimiter.php` - Rate limiter class with database storage
  - `database/migrations/create_rate_limits_table.sql` - Database schema

- **Files Modified**:
  - `public_html/api/config.php` - Added rate limiting helper functions
  - `public_html/api/auth.php` - Login rate limiting (5/min)
  - `public_html/api/tours.php` - Auto rate limiting by HTTP method
  - `public_html/api/guides.php` - Auto rate limiting by HTTP method
  - `public_html/api/payments.php` - Auto rate limiting by HTTP method
  - `public_html/api/tickets.php` - Auto rate limiting by HTTP method
  - `public_html/api/guide-payments.php` - Read rate limiting (100/min)
  - `public_html/api/payment-reports.php` - Read rate limiting (100/min)
  - `public_html/api/bokun_sync.php` - Sync rate limiting (10/min)
  - `public_html/api/bokun_webhook.php` - Webhook rate limiting (30/min)

- **Features**:
  - Automatic IP detection (supports Cloudflare, proxies)
  - HTTP headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
  - 429 Too Many Requests response with Retry-After header
  - Skipped in development mode (configurable via RATE_LIMIT_DEV env var)
  - Self-cleaning: 1% chance per request to clean expired records
  - Database event scheduler for hourly cleanup (optional)

- **Deployment Required**:
  1. Run SQL migration: `database/migrations/create_rate_limits_table.sql`
  2. Deploy updated PHP files to production

---

## ✅ PRIORITY TICKETS PAGE FIX & AUTO-SYNC (2026-01-25)

### Priority Tickets API Pagination & Filter Fix
✅ COMPLETED - Fixed Priority Tickets page not displaying today's 9 bookings

- **Issue**: Priority Tickets page showing 0 tickets despite 9 bookings existing for today (2026-01-25)
  - 7 Uffizi tickets, 1 Accademia ticket, 1 Uffizi tour not visible
  - Page was fetching old 2025 records instead of new 2026 data
- **Root Cause 1**: API per_page limit capped at 100
  - `tours.php` line 95: `min(100, intval($_GET['per_page']))`
  - Database sorted by `date ASC`, so old 2025 records (IDs 1-164) returned first
  - New 2026 records (IDs 165-266) were cut off by the 100 limit
- **Root Cause 2**: PriorityTickets.jsx not using `upcoming` filter
  - Was fetching ALL records instead of future bookings only
  - Old 2025 records filled the response before 2026 data
- **Fix Applied**:
  - Increased API per_page max from 100 to 500 in `tours.php` line 95
  - Updated `PriorityTickets.jsx` to use `upcoming: true` filter
  - Now fetches only bookings from today + 60 days forward
- **Ticket Detection Enhancement**:
  - Added "Entrance Ticket" to TICKET_KEYWORDS in `tourFilters.js`
  - Now properly detects "Uffizi Gallery Priority Entrance Tickets"
- **Immediate Result**:
  - ✅ All 9 bookings for today (2026-01-25) now visible
  - ✅ 7 Uffizi tickets displayed correctly
  - ✅ 1 Accademia ticket displayed correctly
  - ✅ 1 Uffizi tour displayed correctly
- **Files Changed**:
  - MODIFIED: `public_html/api/tours.php` (line 95 - increased per_page max to 500)
  - MODIFIED: `src/pages/PriorityTickets.jsx` (line 84 - added upcoming filter)
  - MODIFIED: `src/utils/tourFilters.js` (added "Entrance Ticket" keyword)
- **Priority**: 🔴 CRITICAL - Priority Tickets page was showing no data
- **Deployment Status**: ✅ READY FOR PRODUCTION

### Automatic Bokun Sync System
✅ VERIFIED OPERATIONAL - Auto-sync infrastructure already in place and working

- **Auto-Sync Features**:
  - Syncs on app startup (if last sync > 15 minutes ago)
  - Periodic sync every 15 minutes while app is active
  - Syncs on app focus/visibility change (if > 15 minutes since last sync)
  - Non-intrusive status indicator in bottom-right corner
  - Toast notifications when new bookings are synced
- **Components**:
  - `src/components/BokunAutoSyncProvider.jsx` - Provider component with status indicator
  - `src/hooks/useBokunAutoSync.jsx` - React hook for sync state management
  - `src/services/bokunAutoSync.js` - Service class handling sync logic
- **Integration**: Already wrapped in App.jsx around all routes
- **Admin Only**: Auto-sync only runs for authenticated admin users
- **Files Verified**:
  - `src/App.jsx` - BokunAutoSyncProvider wrapping all routes
  - `src/components/BokunAutoSyncProvider.jsx` - Status indicator component
  - `src/hooks/useBokunAutoSync.jsx` - Hook with 15-minute interval
  - `src/services/bokunAutoSync.js` - Sync service with focus/visibility listeners

### Debug Files Cleanup
✅ COMPLETED - Removed temporary investigation files

- Removed: `public_html/api/today_tickets.php`
- Removed: `public_html/api/check_2026.php`
- Removed: `public_html/api/db_check.php`
- Removed: `public_html/api/bokun_debug.php`
- Removed: `verify_sync.cjs`

## ✅ PRODUCTION PAYMENTS PAGE FIX (2025-10-26)

### Payment System Database Views and Table Name Correction
✅ COMPLETED - Fixed production payments page error by creating missing database views and correcting table references

- **Issue**: Production payments page showing "Error loading payment data: Failed to load payment overview"
  - Page URL: https://withlocals.deetech.cc/payments
  - Local development page working perfectly
  - Production API returning 500 Internal Server Error
- **Root Cause 1**: Missing database views
  - `guide_payment_summary` view did not exist in production
  - `monthly_payment_summary` view did not exist in production
  - API file `guide-payments.php` line 41 required these views
- **Root Cause 2**: Table name mismatch
  - API files referenced `payment_transactions` table
  - Production database uses `payments` table (not `payment_transactions`)
  - Development and production had different table naming
- **Fix Applied**:
  - Created `guide_payment_summary` view using `payments` table
  - Created `monthly_payment_summary` view using `payments` table
  - Updated `public_html/api/guide-payments.php` - replaced all `payment_transactions` with `payments`
  - Updated `public_html/api/payments.php` - replaced all `payment_transactions` with `payments`
- **Immediate Result**:
  - ✅ Database views created successfully in production
  - ✅ API endpoint working: `/api/guide-payments.php?action=overview`
  - ✅ API endpoint working: `/api/guide-payments.php`
  - ✅ Payments page fully operational: https://withlocals.deetech.cc/payments
- **Impact**:
  - ✅ All 4 payment page tabs now functional (Overview, Guide Payments, Record Payment, Reports)
  - ✅ Payment statistics displaying correctly
  - ✅ Guide payment summaries working
  - ✅ Payment reports accessible
  - ✅ Payment recording operational
- **Files Changed**:
  - CREATED: `create_views_production.php` - Database view creation script
  - MODIFIED: `public_html/api/guide-payments.php` - Table name corrections
  - MODIFIED: `public_html/api/payments.php` - Table name corrections
  - CREATED: `PAYMENTS_PAGE_FIX.md` - Complete technical documentation
- **Production Database Changes**:
  - Created view: `guide_payment_summary` (using `payments` table)
  - Created view: `monthly_payment_summary` (using `payments` table)
- **Priority**: 🔴 CRITICAL - Payments page was completely broken
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Views deployed to: u803853690_withlocals database
  - API files updated on production server
  - Verified at: https://withlocals.deetech.cc/payments

## ✅ BOKUN SYNC DATE RANGE FIX (2025-10-26)

### Bokun Auto-Sync Optimization
✅ COMPLETED - Fixed sync to include past bookings, not just future ones

- **Issue**: Bokun sync was only fetching bookings from TODAY forward
  - Example: October 24 bookings (2 days in past) were missing from production
  - Production showed "0 of 23" while development showed "6 of 27"
- **Root Cause**: `bokun_sync.php` line 89 using `date('Y-m-d')` as start date
  - Only synced from current date forward (future bookings only)
  - Missed any bookings from yesterday or earlier
- **Fix Applied**:
  - Changed start date from TODAY to **7 DAYS AGO**: `strtotime('-7 days')`
  - Extended end date from +14 days to **+30 DAYS FORWARD**: `strtotime('+30 days')`
  - Now syncs 37-day rolling window (past 7 days + next 30 days)
- **Immediate Result**: Synced 43 bookings from Oct 19 to Nov 25
  - All 6 missing October 24 bookings imported successfully
  - Production ticket count increased from 23 to 28
- **Impact**:
  - ✅ Auto-sync now includes recent past bookings (7-day lookback)
  - ✅ Handles same-day and yesterday's bookings correctly
  - ✅ Prevents missing bookings due to sync timing
  - ✅ Better coverage for rescheduled bookings
  - ✅ No manual intervention needed going forward
- **Files Changed**:
  - MODIFIED: `public_html/api/bokun_sync.php` (Lines 87-94)
- **Priority**: 🟡 IMPORTANT - Affects booking data completeness
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Deployed to: https://withlocals.deetech.cc
  - Manual sync triggered: 43 bookings synced
  - October 24 bookings verified in production database
  - Auto-sync (every 15 minutes) now uses new date range

## 🔴 CRITICAL: TOUR DATE BUG FIX (2025-10-26)

### Priority Tickets / Tours Date Display Bug
✅ COMPLETED - Fixed critical bug where tours displayed under booking creation date instead of actual tour date

- **Issue**: Tours showing under wrong dates in Priority Tickets and Tours pages
  - Example: October 2 tour displayed under September 9 (booking creation date)
  - Caused confusion in tour scheduling and guide assignments
- **Root Cause**: `BokunAPI.php` using `booking['creationDate']` as fallback when tour date fields not found
  - creationDate = when customer MADE the booking
  - Should use startDateTime/startDate = when tour HAPPENS
- **Fix Applied**:
  - Removed incorrect `creationDate` fallback in `transformBookingToTour()` function
  - Added validation to ensure tour date exists before importing
  - Added detailed error logging for debugging
  - Throws exception for bookings without valid tour dates
- **Database Fix Script**: Created `fix_tour_dates.php` to correct existing wrong dates
  - Re-parses `bokun_data` JSON field
  - Extracts correct tour date from `startDateTime` or `startDate`
  - Updates all affected tours in database
- **Impact**:
  - ✅ Priority Tickets page now shows tours by actual tour date
  - ✅ Tour scheduling accurate for guide assignments
  - ✅ No more confusion about which tours are on which days
  - ✅ Better UX for tour guides and operations team
- **Files Changed**:
  - MODIFIED: `public_html/api/BokunAPI.php` (Lines 326-339)
  - NEW: `public_html/api/fix_tour_dates.php`
  - NEW: `TOUR_DATE_BUG_FIX.md` (Complete deployment guide)
- **Priority**: 🔴 CRITICAL - Affects tour scheduling and operations
- **Deployment Status**: ✅ DEPLOYED TO PRODUCTION (October 26, 2025)
  - Deployed to: https://withlocals.deetech.cc
  - Fix script executed: 65 tours updated successfully
  - Zero errors during deployment
  - Database dates corrected from booking creation dates to actual tour dates

## ✅ REACT ROUTER 404 FIX (2025-10-26)

### Priority Tickets Direct URL 404 Error Fix
✅ COMPLETED - Fixed 404 errors when accessing routes directly or refreshing pages

- **Issue**: Direct URL access to `https://withlocals.deetech.cc/priority-tickets` returned 404 error
- **Root Cause**: Missing .htaccess file for Apache URL rewriting to support React Router SPA
- **Solution**: Created `public/.htaccess` with Apache rewrite rules
- **Fix Details**:
  - Created `public/.htaccess` with mod_rewrite rules
  - Redirects all non-file requests to `index.html` (except /api/)
  - Vite automatically copies file to `dist/` during build
  - Added security headers and caching optimization
- **Impact**:
  - ✅ All routes now accessible via direct URL
  - ✅ Browser refresh works on all pages
  - ✅ Pages can be bookmarked and shared
  - ✅ Improved SEO (all pages accessible to search engines)
- **Files Created**:
  - NEW: `public/.htaccess` - Apache rewrite configuration
  - NEW: `PRIORITY_TICKETS_404_FIX.md` - Complete deployment guide
- **Testing**: Verified with Puppeteer - direct URL access works correctly
- **Deployment**: Ready for production - see `PRIORITY_TICKETS_404_FIX.md` for instructions

## ✅ CRITICAL PRODUCTION DATABASE FIXES (2025-10-25)

### Database Schema Synchronization
✅ COMPLETED - Fixed critical column mismatches between local and production

- **Root Cause**: Production database was missing 2 columns and had 1 incorrect enum value
- **Missing Columns Added**:
  - `bokun_experience_id VARCHAR(255)` - Track Bokun experience IDs for API sync
  - `last_sync TIMESTAMP` - Track last synchronization time for Bokun integration
- **Enum Value Fixed**:
  - Modified `payment_status` enum from ('unpaid','partial','paid') to include 'overpaid' option
  - Now matches local database: ENUM('unpaid','partial','paid','overpaid')
- **Sessions Table Fixed**: Added missing `token VARCHAR(255)` column (fixed login errors)
- **Result**: Production database now has 40 columns matching local development exactly

### Priority Tickets Date Filter Fix
✅ DEPLOYED - Changed default behavior to show all ticket bookings

- **Issue**: Page defaulted to today's date, showing no data when all tickets were from past dates
- **Fix Location**: `src/pages/PriorityTickets.jsx` line 66
- **Before**: `date: new Date().toISOString().split('T')[0]` (defaulted to today)
- **After**: `date: ''` (empty = show all dates)
- **Result**: Page now displays all 50+ museum ticket bookings on load

### Error Resolution
Fixed all production application failures

- ❌ **Before**: "Unknown column 'bokun_experience_id'" errors on tour creation
- ❌ **Before**: "Unknown column 'last_sync'" errors on Bokun sync
- ❌ **Before**: "Unknown column 'token'" errors on login
- ❌ **Before**: Empty Priority Tickets page due to date filter
- ✅ **After**: All CRUD operations working correctly on production
- ✅ **After**: Authentication and session management functional
- ✅ **After**: Bokun synchronization operational
- ✅ **After**: All pages displaying data correctly

## ✅ BOOKING DETAILS MODAL & ENHANCED UX (2025-10-24)

### Booking Details Modal Component
✅ DEPLOYED TO PRODUCTION - Comprehensive modal for viewing complete booking information

- **New Component**: `src/components/BookingDetailsModal.jsx` - Reusable across Priority Tickets and Tours pages
- **6 Detailed Sections**:
  1. Tour Information (date, time, museum, duration, title)
  2. Main Contact (name, email, phone from Bokun API)
  3. Participants (adults/children breakdown, INFANT excluded)
  4. Booking Details (channel, status, confirmation codes, pricing)
  5. Special Requests (customer requirements from Bokun)
  6. Internal Notes (editable with save functionality)
- **Data Extraction**: Parses `bokun_data` JSON field and `priceCategoryBookings` array
- **Responsive Design**: 800px width on desktop, full screen on mobile
- **User Experience**: ESC key to close, click backdrop to close, body scroll locked when open

### Priority Tickets Page Major Enhancements
✅ DEPLOYED TO PRODUCTION

- **Removed Contact Column**: Moved customer contact details to modal for cleaner table view
- **Participant Breakdown**: Shows "2A / 1C" format (adults/children), INFANT tickets excluded
- **Default Today's Date**: Page automatically shows today's bookings on load
- **Morning Bookings First**: Chronological sorting (09:00, 12:00, 14:00) - earliest time first
- **Click to View Details**: Click any booking row to open comprehensive details modal
- **stopPropagation**: Prevents modal from opening during inline notes editing

### Tours Page Modal Integration
✅ DEPLOYED TO PRODUCTION

- Added same booking details modal functionality to Tours page
- Click any tour row to view complete booking information
- stopPropagation on guide assignment and notes columns
- Seamless integration with existing guide assignment workflow

### Database Schema Verification
✅ CONFIRMED ALL COLUMNS EXIST

- Verified production database has ALL required columns:
  - `language` VARCHAR(50) - For language detection ✅
  - `rescheduled` TINYINT(1) - Rescheduling flag ✅
  - `original_date` DATE - Original tour date ✅
  - `original_time` TIME - Original tour time ✅
  - `rescheduled_at` TIMESTAMP - When rescheduled ✅
  - `payment_notes` TEXT - Payment notes ✅
  - `notes` TEXT - Booking notes ✅
  - `bokun_data` TEXT - Full Bokun JSON ✅
- Production database `u803853690_withlocals` fully up-to-date
- No migration needed - all features enabled

### GitHub Integration
✅ COMPLETED

- Repository: https://github.com/DhaNu1204/guide-florence-with-locals.git
- All changes pushed to master branch
- Complete deployment documentation created

### Files Modified/Created

- NEW: `src/components/BookingDetailsModal.jsx` (409 lines)
- MODIFIED: `src/pages/PriorityTickets.jsx` (participant breakdown, modal integration, default date, sorting)
- MODIFIED: `src/pages/Tours.jsx` (modal integration, click handlers)
- NEW: `DEPLOYMENT_PLAN.md` (Complete deployment guide)
- NEW: `DEPLOYMENT_SUCCESS.md` (Deployment verification report)
- NEW: `DATABASE_SCHEMA_COMPARISON.md` (Schema comparison report)
- NEW: `MIGRATION_INSTRUCTIONS.md` (Database migration guide)

## ✅ CRITICAL BUG FIXES & UI ENHANCEMENTS (2025-10-19)

### Dashboard Chronological Sorting
✅ COMPLETED - Fixed Unassigned Tours and Upcoming Tours sorting

- **Issue**: Tours were only sorted by date, not time, causing incorrect display order
- **Fix Location**: `src/components/Dashboard.jsx` lines 86-91 (Unassigned Tours), lines 108-113 (Upcoming Tours)
- **Solution**: Implemented combined date+time sorting: `new Date(a.date + ' ' + a.time)` for accurate chronological order
- **Result**: Tours now display in true chronological sequence (e.g., 19/10 10:00, 19/10 17:00, 19/10 17:30, 21/10 09:30)

### Tours Page CRUD Operations Fix
✅ COMPLETED - Resolved guide assignment and notes persistence

- **Issue**: Guide assignments and notes were not saving to database despite correct frontend implementation
- **Root Cause**: Backend `tours.php` PUT handler (lines 307-326) missing field handling for `guide_id` and `notes`
- **Fix Applied**:
  - Added backward-compatible handling for both `guideId` (camelCase) and `guide_id` (snake_case)
  - Added complete `notes` field handling in PUT request
  - Implemented proper NULL value handling for guide assignments
- **Testing**: Verified with curl - both fields now persist correctly to database
- **Files Modified**: `public_html/api/tours.php` lines 307-326

### Priority Tickets Page Redesign
✅ COMPLETED - Enhanced museum ticket booking management

- **Removed**: Confirmation column (bokun_confirmation_code/external_id display)
- **Added**: Notes column with full inline CRUD functionality
- **Features Implemented**:
  - Click-to-edit notes interface with textarea expansion
  - Save (green checkmark) and Cancel (red X) buttons
  - Real-time database persistence using `updateTour()` API
  - Visual feedback: "Click to add notes..." placeholder for empty notes
  - Hover effects for improved user experience
- **Column Width Balancing**: Optimized table layout for better readability
  - Date: 100px, Time: 70px, Museum: 130px, Customer: 120px
  - Contact: 180px (wider for email addresses)
  - Participants: 90px, Booking Channel: 120px
  - Notes: Flexible width (expands to fill remaining space)
- **Database Integration**: Uses existing `notes` column in `tours` table (no schema changes required)
- **Files Modified**:
  - `src/pages/PriorityTickets.jsx` - Complete component update
  - Added state management: `editingNotes`, `savingChanges`
  - Added CRUD functions: `saveNotes()`, `handleNotesChange()`, `cancelNotesEdit()`
- **Icons Added**: FiSave, FiX from react-icons/fi

### Authentication Session Fix
✅ COMPLETED - Resolved login failure

- **Issue**: 500 Internal Server Error during login - sessions table INSERT missing `session_id` field
- **Fix Location**: `public_html/api/auth.php` line 56
- **Solution**: Added `session_id` field to both INSERT statement and parameter binding
- **Result**: Login now works successfully, permanent fix applied

## ✅ AUTOMATIC LANGUAGE DETECTION & PAYMENT STATUS INTELLIGENCE (2025-10-15)

### Multi-Channel Language Extraction
✅ COMPLETED - Automatic tour language detection from Bokun API

- **Method 1 (Viator)**: Extract from booking notes using regex pattern `GUIDE : English`
- **Method 2 (GetYourGuide)**: Match rateId to product rate titles (Italian Tour, Spanish Tour, etc.)
- **Method 3**: Check field locations for language indicators
- **Method 4**: Parse product title for language keywords
- Added `language VARCHAR(50)` column to tours database table
- Successfully extracted and updated 132+ tours with accurate language data
- Language badges displayed in Tours page, Dashboard (Upcoming Tours, Unassigned Tours)
- No default values - only displays actual detected language to prevent incorrect guide assignments

### Smart Payment Status Logic
✅ COMPLETED - Fixed payment tracking confusion

- Corrected distinction between customer platform payments (Bokun INVOICED) vs guide payments
- All Bokun-synced tours now correctly start as 'unpaid' for guide payment tracking
- Reset 127 existing tours from incorrect "paid" status to proper "unpaid" status
- Payment system now accurately tracks what guides need to be paid

### Ticket Product Filtering
✅ COMPLETED - Intelligent filtering of non-tour products

- Excluded "Uffizi Gallery Priority Entrance Tickets" from Tours page display
- Excluded "Skip the Line: Accademia Gallery Priority Entry Ticket" from Tours page
- Added filtering to Dashboard Unassigned Tours section
- Added filtering to Payment Record form to prevent ticket product selection
- Maintains ticket products in database for inventory management while hiding from tour workflows

### Enhanced Data Handling
✅ COMPLETED - Improved robustness

- Fixed PaymentRecordForm to handle paginated getTours() response properly
- Added safety checks for undefined ticket locations in Tickets page
- Improved error handling and null checks throughout

## ✅ CANCELLED BOOKING SYNC & RESCHEDULING SUPPORT (2025-09-30)

### Cancelled Booking Synchronization
✅ COMPLETED - Fixed sync to include cancelled bookings from Bokun API

- Enhanced Bokun API search to include 'CANCELLED' status bookings alongside 'CONFIRMED' and 'PENDING'
- Fixed frontend caching issue that prevented cancelled bookings from displaying
- Added red "Cancelled" status badges in Tours page with proper visual indicators
- Verified cancelled bookings (GET-75173181, VIA-71040572) now properly marked and displayed

### Complete Rescheduling Support
✅ COMPLETED - Full implementation for tour date/time changes

- Added database schema: `rescheduled`, `original_date`, `original_time`, `rescheduled_at` columns
- Enhanced Bokun sync logic to detect date/time changes and preserve original scheduling details
- Implemented orange "Rescheduled" status badges with hover tooltips showing original scheduling
- Prevents duplicate tour entries when clients reschedule bookings in Bokun
- Complete audit trail preservation for customer service and guide coordination

### Frontend Cache Management
✅ COMPLETED - Added refresh functionality

- Added "Refresh" button in Tours page header with spinning animation
- Force cache bypass functionality to ensure latest data display
- Fixed localStorage caching issues that showed stale booking data
- Real-time sync status with proper error handling and user feedback

## ✅ PRODUCTION DEPLOYMENT COMPLETE (2025-09-29)

- **Live Production Site**: https://withlocals.deetech.cc fully operational ✅
- **Critical Bug Fixes**: Resolved all production deployment errors including:
  - Fixed hardcoded localhost URLs in frontend components
  - Corrected API endpoint naming (.php extensions required)
  - Resolved database schema mismatches between development and production
  - Fixed missing payment system database tables
- **Bokun Integration Live**: Successfully synced 47 bookings from Bokun API on production server
- **Dashboard Functionality**: All dashboard components showing live data with proper filtering
- **Payment System Operational**: Complete payment tracking system with guide analytics working on production
- **Environment Configuration**: Proper .env.production setup with VITE_API_URL=https://withlocals.deetech.cc/api
- **Database Migration**: Successfully migrated and configured production MySQL database
- **SSH Deployment Process**: Established automated deployment via SSH (port 65002) to Hostinger hosting

## ✅ Latest Development Update (2025-09-28)

- **Application Branding Update**: Updated application name to "Florence with Locals Tour Guide Management System"
- **Enhanced User Experience**: Improved payment alerts with styled notifications replacing browser alerts
- **Editable Payment Transactions**: Added inline editing capability for payment amounts and methods
- **Layout Reorganization**: Moved user profile and logout to sidebar footer for better navigation
- **100% Mobile Responsive**: Comprehensive mobile responsiveness testing completed across all pages and components
- **UI/UX Optimization**: Sidebar displays clean "Florence with Locals" branding while maintaining full application name in titles

## ✅ Payment System Enhancement (2025-09-20)

- **Payment System Reports Enhancement**: Completed calendar-based date range filtering for payment reports
- **Italian Timezone Support**: All payment dates and reports now use proper Italian timezone (Europe/Rome)
- **Calendar Date Picker**: Advanced date range selector with quick filter buttons (Today, Last 7/30 Days, This Month)
- **Guide Payment Analytics**: Enhanced reports with guide-specific filtering and date range selection
- **Payment API Integration**: Complete CRUD operations for payment transactions with date filtering

## ✅ Bokun API Update (2025-09-13)

- **Bokun API Credentials Updated**: Corrected API keys with actual credentials from dashboard
- **2025 Date Corrections**: All date references updated from 2024 to proper 2025 dates
- **Enhanced Monitoring System**: Real-time API diagnostics with auto-refresh capabilities
- **Comprehensive Support Documentation**: Updated Bokun support reply with correct API keys and 2025 August tour examples
- **API Status Confirmed**: HTTP 303 redirects confirm authentication works but BOOKINGS_READ permission needed
- **Server Environment**: Both frontend (port 5173) and backend (port 8080) running successfully

## ✅ UI/UX Modernization Complete (2025-08-29)

- **Responsive Sidebar Navigation**: Desktop left sidebar, mobile collapsible menu
- **Modern Component System**: Card, Button, Input components with consistent styling
- **Compact Tour Cards**: Horizontal layout on desktop, single column list view
- **Mobile-First Design**: 100% responsive across all screen sizes
- **Icon Integration**: React Icons (Fi) throughout the interface
- **Color-coded Status System**: Visual indicators for tour status, payment, etc.

## ✅ Enhanced Functionality

- **Multi-Language Guide Support**: Checkbox selection for up to 3 languages per guide
- **Email Integration**: Email field added to guide registration
- **Separated Bokun Integration**: Dedicated page at `/bokun-integration`
- **Improved Error Handling**: Comprehensive error boundaries and user feedback
- **Data Validation**: Frontend and backend validation for all forms
- **Ticket Management System**: Museum entrance ticket inventory with date/time organization

## ✅ Database & API Verification

- **All CRUD Operations Tested**: Create, Read, Update, Delete for Tours, Guides, and Tickets
- **RESTful API Endpoints**: Proper HTTP methods and status codes
- **Database Integrity**: All foreign keys and relationships verified
- **Performance Optimization**: Efficient queries with proper indexing
