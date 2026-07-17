# API Documentation

**Last Updated**: February 23, 2026

## Production APIs ✅

**Base URL**: https://withlocals.deetech.cc/api

- **Tours**: `/api/tours.php` - Full CRUD operations ✅ LIVE
- **Guides**: `/api/guides.php` - Full CRUD operations with pagination ✅ LIVE
- **Payments**: `/api/payments.php` - Payment transaction CRUD with date filtering ✅ LIVE
- **Guide Payments**: `/api/guide-payments.php` - Guide payment summaries, analytics, and pending tours ✅ LIVE
- **Payment Reports**: `/api/payment-reports.php` - Payment reports with date range filtering ✅ LIVE
- **Tickets**: `/api/tickets.php` - Museum ticket inventory management (Uffizi/Accademia) ✅ LIVE
- **Authentication**: `/api/auth.php` - Login/logout functionality (rate limited: 5/min) ✅ LIVE
- **Bokun Sync**: `/api/bokun_sync.php` - Bokun API integration (rate limited: 10/min) ✅ LIVE

## Development APIs

**Base URL**: http://localhost:8080/api

- **Tours**: `/api/tours.php` - Full CRUD operations
- **Guides**: `/api/guides.php` - Full CRUD operations
- **Payments**: `/api/payments.php` - Payment transaction CRUD with date filtering
- **Guide Payments**: `/api/guide-payments.php` - Guide payment summaries and analytics
- **Payment Reports**: `/api/payment-reports.php` - Payment reports with date range filtering
- **Tickets**: `/api/tickets.php` - Museum ticket inventory management (Uffizi/Accademia)
- **Authentication**: `/api/auth.php` - Login/logout functionality

## Tours API Parameters

### GET /api/tours.php

| Parameter | Type | Default | Max | Description |
|-----------|------|---------|-----|-------------|
| page | int | 1 | - | Page number for pagination |
| per_page | int | 50 | 500 | Records per page (max 500) |
| date | string | - | - | Filter by specific date (YYYY-MM-DD) |
| start_date | string | - | - | Start of date range (YYYY-MM-DD), requires end_date |
| end_date | string | - | - | End of date range (YYYY-MM-DD), requires start_date |
| guide_id | int | - | - | Filter by guide ID |
| upcoming | bool | false | - | Show tours from today + 60 days only |
| past | bool | false | - | Show tours from past 40 days (yesterday backwards) |

**Filter priority**: `start_date+end_date` > `past` > `upcoming` > `date` (first match wins).

**Examples**:
- `/api/tours.php?upcoming=true&per_page=500`
- `/api/tours.php?start_date=2026-03-01&end_date=2026-03-15`
- `/api/tours.php?past=true&guide_id=5`

## Bokun Integration APIs

- **Test Connection**: `/api/bokun_sync.php?action=test`
- **Sync Bookings**: `/api/bokun_sync.php?action=sync`
- **Get Config**: `/api/bokun_sync.php?action=config`
- **Get Unassigned**: `/api/bokun_sync.php?action=unassigned`
- **Auto-Assign Guide**: `/api/bokun_sync.php?action=auto-assign`

### Auto-Sync Behavior
- Syncs automatically every 15 minutes for admin users
- Syncs on app startup (if last sync > 15 minutes ago)
- Syncs when app regains focus (if last sync > 15 minutes ago)
- Status indicator shows sync progress in bottom-right corner

## Guide Payments API (Updated Jan 29, 2026)

### GET /api/guide-payments.php

| Action | Description | Response |
|--------|-------------|----------|
| `?action=overview` | Payment system overview stats | `{ total_guides, total_payments, unpaid_tours }` |
| `?action=pending_tours` | Tours awaiting guide payment | `{ success, count, data: [...tours] }` |
| (no action) | All guides with payment summaries | Array of guide payment data |

### Pending Tours Endpoint (NEW)

```http
GET /api/guide-payments.php?action=pending_tours
```

Returns tours that:
- Have a guide assigned (`guide_id IS NOT NULL`)
- Are not cancelled
- Have a date in the past (completed tours)
- Have NO payment record in the `payments` table
- Are NOT ticket products (excludes Entry Ticket, Entrance Ticket, Priority Ticket, etc.)

**Response**:
```json
{
  "success": true,
  "count": 5,
  "data": [
    {
      "id": 123,
      "title": "Florence Walking Tour",
      "date": "2026-01-15",
      "time": "09:00",
      "guide_id": 1,
      "guide_name": "Marco Rossi",
      "participants": 4,
      "booking_channel": "Viator"
    }
  ]
}
```

### Tour Payment Status Endpoint

```http
GET /api/guide-payments.php?action=tour_status
```

Returns payment status counts based on actual `payments` table:
- **unpaid**: Tours with no payment records
- **paid**: Tours with at least one payment record

## Utility APIs

- **Database Check**: `/api/database_check.php` - Verify database structure and connections

## Rate Limiting (Added Jan 29, 2026)

All API endpoints are protected by database-backed rate limiting.

### Rate Limits by Endpoint Type

| Type | Limit | Window | Used By |
|------|-------|--------|---------|
| login | 5 | 1 minute | auth.php login action |
| read | 100 | 1 minute | GET requests |
| write/create | 30 | 1 minute | POST requests |
| update | 30 | 1 minute | PUT requests |
| delete | 10 | 1 minute | DELETE requests |
| bokun_sync | 10 | 1 minute | bokun_sync.php |
| webhook | 30 | 1 minute | bokun_webhook.php |

### Rate Limit Response Headers

All responses include rate limit headers:

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1706540400
```

### 429 Too Many Requests

When rate limit is exceeded:

```json
{
  "error": "Rate limit exceeded. Please try again in 45 seconds",
  "retry_after": 45
}
```

Response headers:
```
Retry-After: 45
```

## Authentication

All API endpoints (except login) require authentication via session token.

### Login Credentials

- **Admin**: dhanu / ***REMOVED***
- **Viewer**: ***REMOVED*** / ***REMOVED***

### Session Management

The system uses PHP sessions for authentication. After successful login, a session token is created and stored in the `sessions` table. The frontend maintains the authentication state using React Context.

## PDF Report Generation (Added Jan 29, 2026)

PDF reports are generated client-side using jsPDF. No API endpoints required.

### Available Reports

| Report | Function | Description |
|--------|----------|-------------|
| Guide Payment Summary | `generateGuidePaymentSummaryPDF()` | Overview of all guides with totals |
| Pending Payments | `generatePendingPaymentsPDF()` | Tours awaiting guide payment |
| Payment Transactions | `generatePaymentTransactionsPDF()` | Detailed payment history |
| Monthly Summary | `generateMonthlySummaryPDF()` | Monthly payment breakdown |

### Usage

```javascript
import {
  generateGuidePaymentSummaryPDF,
  generatePendingPaymentsPDF
} from '../utils/pdfGenerator';

// Download PDF
generateGuidePaymentSummaryPDF(guidesData);
generatePendingPaymentsPDF(pendingToursData);
```

## Daily P&L API — `pnl.php` (Added Jul 6, 2026, admin-only)

All requests require an admin token (`Middleware::requireRole($conn, 'admin')`). Writes go only to the self-provisioned `pnl_settings` and `pnl_tour_costs` tables — no payment logic.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/pnl.php?date=YYYY-MM-DD` | Day detail: per-tour-unit rows (`g<group_id>` / `t<id>`) with pax breakdown, revenue (retail/commission/net, `estimated` flag), auto+override costs, profit; day totals; settings |
| GET | `/api/pnl.php?start=YYYY-MM-DD&end=YYYY-MM-DD` | Range summary (max 92 days): per-day totals (`tour_units`/`ticket_units` split), grand totals, `by_category[]` (Combo/Uffizi/Accademia/Pitti/Borghese/Mixed/Other/Tickets), `monthly_overhead`, `profit_after_overhead` |
| GET | `/api/pnl.php?action=settings` | Rate settings key/value map (guide rates incl. `guide_rate_private`, museum ticket prices incl. `ticket_uffizi_*_pm`, radio/gelato per person, `outsource_fee`, monthly overheads, fallback commission %) |
| POST | `/api/pnl.php?action=settings` | `{settings: {key: value}}` — upsert whitelisted numeric keys |
| POST | `/api/pnl.php?action=costs` | `{tour_unit, date, ticket_cost?, guide_cost?, radio_cost?, gelato_cost?, staff_cost?, other_cost?, revenue_override?, outsourced?, notes?}` — per-unit override upsert; explicit `null` clears an override (`array_key_exists` pattern); `outsourced` stored 0/1 |

Revenue is auto-extracted per booking from stored `bokun_data` (resellerInvoice → sellerCommission+customerInvoice → channel-% estimate). Guide-cost precedence: outsourced > ticket product > private flat rate > Mixed (highest member rate) > category rate. Uffizi bookings with time ≥ 16:00 use the PM ticket rates.
