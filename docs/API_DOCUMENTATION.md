# API Documentation

## Production APIs ✅

**Base URL**: https://withlocals.deetech.cc/api

- **Tours**: `/api/tours.php` - Full CRUD operations ✅ LIVE
- **Guides**: `/api/guides.php` - Full CRUD operations ✅ LIVE
- **Payments**: `/api/payments.php` - Payment transaction CRUD with date filtering ✅ LIVE
- **Guide Payments**: `/api/guide-payments.php` - Guide payment summaries and analytics ✅ LIVE
- **Payment Reports**: `/api/payment-reports.php` - Payment reports with date range filtering ✅ LIVE
- **Tickets**: `/api/tickets.php` - Museum ticket inventory management (Uffizi/Accademia) ✅ LIVE
- **Authentication**: `/api/auth.php` - Login/logout functionality ✅ LIVE

## Development APIs

**Base URL**: http://localhost:8080/api

- **Tours**: `/api/tours.php` - Full CRUD operations
- **Guides**: `/api/guides.php` - Full CRUD operations
- **Payments**: `/api/payments.php` - Payment transaction CRUD with date filtering
- **Guide Payments**: `/api/guide-payments.php` - Guide payment summaries and analytics
- **Payment Reports**: `/api/payment-reports.php` - Payment reports with date range filtering
- **Tickets**: `/api/tickets.php` - Museum ticket inventory management (Uffizi/Accademia)
- **Authentication**: `/api/auth.php` - Login/logout functionality

## Bokun Integration APIs

- **Test Connection**: `/api/bokun_sync.php?action=test`
- **Sync Bookings**: `/api/bokun_sync.php?action=sync`
- **Get Unassigned**: `/api/bokun_sync.php?action=unassigned`
- **Auto-Assign Guide**: `/api/bokun_sync.php?action=auto-assign`

## Utility APIs

- **Database Check**: `/api/database_check.php` - Verify database structure and connections

## Authentication

All API endpoints (except login) require authentication via session token.

### Login Credentials

- **Admin**: dhanu / Kandy@123
- **Viewer**: Sudeshshiwanka25@gmail.com / Sudesh@93

### Session Management

The system uses PHP sessions for authentication. After successful login, a session token is created and stored in the `sessions` table. The frontend maintains the authentication state using React Context.
