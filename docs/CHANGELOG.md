# Changelog - Recent Major Updates

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
