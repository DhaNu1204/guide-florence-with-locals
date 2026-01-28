# COMPREHENSIVE TECHNICAL AUDIT REPORT
## Florence with Locals Tour Guide Management System

**Audit Date:** January 27, 2026
**Production URL:** https://withlocals.deetech.cc
**Status:** Fully Operational
**Auditor:** Claude Opus 4.5

---

## Table of Contents

1. [Project Architecture](#1-project-architecture)
2. [API Endpoints](#2-api-endpoints)
3. [Bokun Integration](#3-bokun-integration)
4. [Admin Dashboard](#4-admin-dashboard)
5. [Database Structure](#5-database-structure)
6. [CRUD Operations](#6-crud-operations)
7. [Security Analysis](#7-security-analysis)
8. [Performance Considerations](#8-performance-considerations)
9. [Summary](#9-summary)

---

## 1. PROJECT ARCHITECTURE

### 1.1 File/Folder Structure

```
guide-florence-with-locals/
├── src/                              # React Frontend (SPA)
│   ├── components/                   # Reusable UI components
│   │   ├── Layout/ModernLayout.jsx   # Main layout with sidebar
│   │   ├── UI/                       # Button, Card, Input components
│   │   ├── Dashboard.jsx             # Stats dashboard
│   │   ├── BookingDetailsModal.jsx   # 6-section booking modal
│   │   ├── BokunAutoSyncProvider.jsx # Auto-sync wrapper
│   │   └── PaymentRecordForm.jsx     # Payment recording
│   ├── pages/                        # Route-level components
│   │   ├── Tours.jsx, Guides.jsx, Payments.jsx
│   │   ├── Tickets.jsx, PriorityTickets.jsx
│   │   ├── BokunIntegration.jsx, EditTour.jsx
│   │   └── Login.jsx
│   ├── contexts/                     # React Context providers
│   │   ├── AuthContext.jsx           # Authentication state
│   │   └── PageTitleContext.jsx      # Dynamic titles
│   ├── hooks/useBokunAutoSync.jsx    # Auto-sync hook
│   ├── services/mysqlDB.js           # API client with caching
│   └── utils/                        # Utility functions
├── public_html/api/                  # PHP Backend (REST API)
│   ├── config.php                    # Smart env detection + DB
│   ├── BaseAPI.php                   # Abstract base class
│   ├── tours.php, guides.php         # CRUD endpoints
│   ├── payments.php, tickets.php     # Entity management
│   ├── auth.php                      # Authentication
│   ├── BokunAPI.php                  # Bokun API client
│   ├── bokun_sync.php                # Sync orchestration
│   ├── bokun_webhook.php             # Webhook handlers
│   └── Validator.php, Logger.php     # Utilities
├── database/                         # SQL schemas & migrations
└── docs/                             # 13+ documentation files
```

### 1.2 Design Patterns Used

| Pattern | Implementation | Location |
|---------|---------------|----------|
| **MVC-like Separation** | Frontend (View) + Backend API (Controller) + MySQL (Model) | Overall architecture |
| **Repository Pattern** | `mysqlDB.js` abstracts all database operations | `src/services/mysqlDB.js` |
| **Context API Pattern** | Auth state shared via React Context | `AuthContext.jsx` |
| **Template Method** | `BaseAPI.php` defines abstract CRUD methods | `public_html/api/BaseAPI.php` |
| **Singleton (config)** | Database connection created once in `config.php` | `config.php` |
| **Factory Pattern** | `BokunAPI` instantiation with config injection | `bokun_sync.php:187` |
| **Observer Pattern** | Auto-sync provider watches visibility changes | `BokunAutoSyncProvider.jsx` |

### 1.3 Frontend-Backend Separation

```
┌─────────────────────────────────────────────────────────────┐
│                    FRONTEND (React SPA)                      │
│  - Vite dev server (5173) / Static build (dist/)             │
│  - Axios HTTP client with interceptors                       │
│  - 1-minute client-side caching                              │
│  - localStorage fallback for offline                         │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼ REST API (JSON)
┌─────────────────────────────────────────────────────────────┐
│                    BACKEND (PHP 8.2)                         │
│  - Standalone PHP files (no framework)                       │
│  - CORS headers configured in config.php                     │
│  - MySQLi prepared statements                                │
│  - Smart environment detection (dev/prod)                    │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     DATABASE (MySQL 8.0)                     │
│  - 8+ tables with foreign key relationships                  │
│  - JSON columns for Bokun data                               │
│  - Indexes on date, guide_id, booking_channel                │
└─────────────────────────────────────────────────────────────┘
```

### 1.4 Key Dependencies

#### Frontend (package.json)

| Dependency | Version | Purpose |
|------------|---------|---------|
| React | 18.x | UI library |
| Vite | 5.x | Build tool |
| TailwindCSS | 3.4.x | Styling |
| React Router | 7.5.x | Client-side routing |
| Axios | 1.8.x | HTTP client |
| @sentry/react | 10.36.x | Error monitoring |
| date-fns | 4.1.x | Date utilities |
| react-datepicker | 8.3.x | Date picker UI |

#### Backend (PHP)

- PHP 8.2+ with `curl`, `openssl`, `mysqli` extensions
- No external dependencies (standalone PHP)

---

## 2. API ENDPOINTS

### 2.1 Complete Route List

#### Tours API (`tours.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/tours.php` | List tours with pagination |
| `GET` | `/api/tours.php?date=YYYY-MM-DD` | Filter by date |
| `GET` | `/api/tours.php?guide_id=X` | Filter by guide |
| `GET` | `/api/tours.php?upcoming=true` | Today + 60 days |
| `POST` | `/api/tours.php` | Create tour |
| `PUT` | `/api/tours.php/{id}` | Update tour |
| `PUT` | `/api/tours.php/{id}/paid` | Update payment status |
| `PUT` | `/api/tours.php/{id}/cancelled` | Update cancellation status |
| `DELETE` | `/api/tours.php/{id}` | Delete tour |

#### Guides API (`guides.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/guides.php` | List all guides |
| `POST` | `/api/guides.php` | Create/update guide |
| `DELETE` | `/api/guides.php/{id}` | Delete guide |

#### Payments API (`payments.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/payments.php` | List all payments |
| `GET` | `/api/payments.php?id=X` | Get payment by ID |
| `GET` | `/api/payments.php?tour_id=X` | Payments for tour |
| `POST` | `/api/payments.php` | Record payment |
| `PUT` | `/api/payments.php?id=X` | Update payment |
| `DELETE` | `/api/payments.php?id=X` | Delete payment |

#### Guide Payments API (`guide-payments.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/guide-payments.php` | Guide payment summaries |

#### Payment Reports API (`payment-reports.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/payment-reports.php` | Payment reports with date filtering |

#### Tickets API (`tickets.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/tickets.php` | List tickets |
| `POST` | `/api/tickets.php` | Create ticket |
| `PUT` | `/api/tickets.php/{id}` | Update ticket |
| `DELETE` | `/api/tickets.php/{id}` | Delete ticket |

#### Bokun Sync API (`bokun_sync.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/bokun_sync.php?action=config` | Get Bokun config |
| `GET` | `/api/bokun_sync.php?action=sync` | Trigger sync |
| `GET` | `/api/bokun_sync.php?action=test` | Test connection |
| `GET` | `/api/bokun_sync.php?action=sync-history` | Get sync logs |
| `GET` | `/api/bokun_sync.php?action=sync-info` | Get sync info |
| `GET` | `/api/bokun_sync.php?action=unassigned` | Get unassigned tours |
| `POST` | `/api/bokun_sync.php?action=config` | Save config |
| `POST` | `/api/bokun_sync.php?action=sync` | Trigger sync (POST) |
| `POST` | `/api/bokun_sync.php?action=full-sync` | Full sync (1yr) |
| `POST` | `/api/bokun_sync.php?action=auto-assign` | Auto-assign guide |

#### Webhook API (`bokun_webhook.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/bokun_webhook.php` | Receive Bokun webhooks |

#### Authentication API (`auth.php`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/auth.php` | Login |
| `GET` | `/api/auth.php?action=verify` | Verify token |

### 2.2 Authentication/Authorization Middleware

**Implementation:** `auth.php`

```
Token-based Authentication Flow:
1. POST /api/auth.php - Login with username/password
2. Server validates credentials, generates 64-char token
3. Token stored in sessions table (24hr expiry)
4. Client sends token in Authorization header
5. GET /api/auth.php?action=verify - Validates token
```

**Security Features:**

- **Password hashing:** `password_verify()` with bcrypt
- **Rate limiting:** 5 attempts per IP per 5 minutes
- **Session tokens:** `bin2hex(random_bytes(32))` - 64 character tokens
- **Token expiry:** 24 hours in database

**Roles:**

| Role | Permissions |
|------|-------------|
| `admin` | Full CRUD, sync, config changes |
| `viewer` | Read-only access (UI enforced) |

### 2.3 Request/Response Structures

#### Paginated Response (tours.php)

```json
{
  "data": [
    {
      "id": 123,
      "title": "Uffizi Gallery Tour",
      "date": "2026-02-15",
      "time": "09:00",
      "duration": "2 hours",
      "guide_id": 5,
      "guide_name": "Sofia Romano",
      "customer_name": "John Smith",
      "customer_email": "john@example.com",
      "customer_phone": "+1234567890",
      "participants": 4,
      "payment_status": "unpaid",
      "total_amount_paid": 0,
      "expected_amount": 200.00,
      "cancelled": false,
      "rescheduled": false,
      "booking_channel": "Viator",
      "language": "English",
      "bokun_booking_id": "12345678",
      "last_sync": "2026-01-27 10:30:00"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 50,
    "total": 234,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
  }
}
```

#### Payment Create Request

```json
{
  "tour_id": 123,
  "guide_id": 5,
  "amount": 150.00,
  "payment_method": "cash",
  "payment_date": "2026-01-27",
  "payment_time": "14:30",
  "transaction_reference": "TXN-001",
  "notes": "Payment received"
}
```

### 2.4 Rate Limiting

| Location | Limit | Window |
|----------|-------|--------|
| Login attempts | 5 attempts | 5 minutes per IP |
| Bokun API calls | 400 requests | Per minute (client-side) |

**Implementation:** `BokunAPI.php:34-50` - Request counter with automatic reset

---

## 3. BOKUN INTEGRATION

### 3.1 How Booking Data is Fetched

**Location:** `BokunAPI.php`

```
┌─────────────────────────────────────────────────────────────┐
│                     BOKUN API FLOW                           │
└─────────────────────────────────────────────────────────────┘

1. Authentication (HMAC-SHA1)
   ├── Signature = Base64(HMAC-SHA1(Date+AccessKey+Method+Path))
   └── Headers: X-Bokun-Date, X-Bokun-AccessKey, X-Bokun-Signature

2. Booking Fetch Strategy (getBookings method)
   ├── Query SUPPLIER role → OTA bookings (Viator, GetYourGuide)
   ├── Query SELLER role → Direct bookings
   └── Deduplicate by booking ID

3. Pagination
   ├── Default pageSize: 200
   ├── Max pages: 10 (2000 bookings limit)
   └── Multi-page iteration with seenIds tracking

4. Data Transformation (transformBookingToTour)
   ├── Extract date from startDateTime (UTC → Europe/Rome)
   ├── Extract time from startTimeStr (local time - CRITICAL)
   ├── Parse customer info, participants, language
   └── Store raw bokun_data as JSON for reference
```

**API Endpoint Used:**

```
POST https://api.bokun.is/booking.json/booking-search

Request Body:
{
  "bookingRole": "SUPPLIER" | "SELLER",
  "bookingStatuses": ["CONFIRMED", "PENDING", "CANCELLED"],
  "pageSize": 200,
  "page": 0,
  "startDateRange": {
    "from": "2026-01-20T00:00:00.000Z",
    "to": "2026-05-20T23:59:59.999Z"
  }
}
```

### 3.2 Webhook Handlers

**Location:** `bokun_webhook.php`

| Topic | Handler | Action |
|-------|---------|--------|
| `bookings/create` | `handleBookingCreated()` | Create placeholder tour |
| `bookings/update` | `handleBookingUpdated()` | Update last_sync timestamp |
| `bookings/cancel` | `handleBookingCancelled()` | Set `cancelled = 1` |
| `experiences/availability_update` | `handleAvailabilityUpdate()` | Log for future use |

**Webhook Headers Expected:**

- `X-Bokun-Topic` - Event type
- `X-Bokun-Booking-Id` - Booking identifier
- `X-Bokun-ExperienceBooking-Id` - Experience booking ID

**Webhook Logging:**

```sql
INSERT INTO bokun_webhook_logs (topic, booking_id, experience_booking_id, payload, error_message)
VALUES (?, ?, ?, ?, ?)
```

### 3.3 Data Sync Frequency and Mechanism

**Auto-Sync System:** `BokunAutoSyncProvider.jsx` + `useBokunAutoSync.jsx`

| Trigger | Frequency | Type |
|---------|-----------|------|
| App startup | If last sync > 15 min | auto |
| Periodic interval | Every 15 minutes | auto |
| Visibility change | On tab focus | auto |
| Manual button | On-demand | manual |
| Full sync | On-demand | full |

**Sync Date Ranges (defined in bokun_sync.php):**

```php
define('DEFAULT_SYNC_DAYS', 120);    // Regular sync: -7 to +120 days
define('FULL_SYNC_DAYS', 365);       // Full sync: -7 to +365 days
define('PAST_DAYS_BUFFER', 7);       // Always include past week
```

**Sync Logging:**

All sync operations are logged to the `sync_logs` table:

```sql
INSERT INTO sync_logs (
    sync_type, start_date, end_date, status,
    bookings_found, bookings_synced, bookings_created,
    bookings_updated, bookings_failed,
    error_message, triggered_by, duration_seconds
)
```

### 3.4 Error Handling for API Failures

**Location:** `BokunAPI.php`

```php
// Retry mechanism for rate limiting (429)
if ($httpCode === 429 && $retryCount < 3) {
    $retryAfter = isset($headers['Retry-After']) ? intval($headers['Retry-After']) : 60;
    sleep($retryAfter);
    return $this->makeRequest($method, $endpoint, $data, $retryCount + 1);
}

// Comprehensive logging
error_log("BokunAPI: Making {$method} request to {$url}");
error_log("BokunAPI: Response code: {$httpCode}");
```

**Error Response Handling:**

| HTTP Code | Handling |
|-----------|----------|
| 200 | Success, process response |
| 401 | Authentication error, check credentials |
| 429 | Rate limited, auto-retry up to 3 times |
| 4xx | Client error, throw exception with message |
| 5xx | Server error, throw exception with message |
| cURL error | Network error, log and throw exception |

**Sync Failure Logging:**

```php
updateSyncLog($logId, 'failed', [
    'found' => 0,
    'synced' => 0,
    'created' => 0,
    'updated' => 0,
    'failed' => 0
], $e->getMessage(), $duration);
```

---

## 4. ADMIN DASHBOARD

### 4.1 Available Features and Modules

| Module | Route | Description |
|--------|-------|-------------|
| **Dashboard** | `/` | Stats overview, unassigned tours, upcoming tours |
| **Tours** | `/tours` | Full CRUD, guide assignment, filtering |
| **Guides** | `/guides` | Guide management with multi-language support |
| **Payments** | `/payments` | Payment recording, date filtering |
| **Tickets** | `/tickets` | Museum ticket inventory management |
| **Priority Tickets** | `/priority-tickets` | Ticket bookings with modal and upcoming filter |
| **Bokun Integration** | `/bokun-integration` | Manual sync, config, history |

### 4.2 Role-Based Access Control (RBAC)

**Implementation:** `AuthContext.jsx`

```javascript
const isAdmin = () => userRole === 'admin';
```

**Database Roles:**

```sql
CREATE TABLE users (
  role enum('admin','viewer') NOT NULL DEFAULT 'viewer'
);
```

**Permission Matrix:**

| Feature | Admin | Viewer |
|---------|-------|--------|
| View Dashboard | Yes | Yes |
| View Tours | Yes | Yes |
| Create/Edit Tours | Yes | No |
| Delete Tours | Yes | No |
| Manage Guides | Yes | No |
| Record Payments | Yes | No |
| Bokun Sync | Yes | No |
| System Config | Yes | No |

**Protected Routes:** `App.jsx`

```jsx
const ProtectedRoute = ({ children }) => {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }
  return children;
};
```

### 4.3 Key UI Components and Their Functions

| Component | File | Lines | Function |
|-----------|------|-------|----------|
| **ModernLayout** | `Layout/ModernLayout.jsx` | ~300 | Responsive sidebar navigation, header |
| **Dashboard** | `Dashboard.jsx` | 376 | Stats cards, unassigned tours, upcoming tours |
| **BookingDetailsModal** | `BookingDetailsModal.jsx` | 409 | 6-section booking details view |
| **TourCards** | `TourCards.jsx` | ~200 | Compact horizontal tour cards |
| **CardView** | `CardView.jsx` | ~150 | Container for tour cards |
| **PaymentRecordForm** | `PaymentRecordForm.jsx` | ~250 | Payment entry with Italian timezone |
| **BokunSync** | `BokunSync.jsx` | ~300 | Sync controls, status, history |
| **BokunAutoSyncProvider** | `BokunAutoSyncProvider.jsx` | ~100 | Background sync wrapper |

**Dashboard Stats Displayed:**

- Unassigned Tours (future tours without guide)
- Unpaid Tours (past tours pending payment)
- Upcoming Tours list (sorted chronologically)
- Unassigned Tours list (need guide assignment)
- Last sync time indicator
- Manual sync button

---

## 5. DATABASE STRUCTURE

### 5.1 Tables and Relationships (ERD Summary)

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    users     │     │    guides    │     │   tickets    │
├──────────────┤     ├──────────────┤     ├──────────────┤
│ id (PK)      │     │ id (PK)      │     │ id (PK)      │
│ username     │     │ name         │     │ museum       │
│ password     │     │ email        │     │ ticket_type  │
│ email        │     │ phone        │     │ date         │
│ role         │     │ languages    │     │ time         │
│ created_at   │     │ notes        │     │ quantity     │
│ updated_at   │     │ created_at   │     │ price        │
└──────────────┘     │ updated_at   │     │ status       │
                     └───────┬──────┘     │ notes        │
                             │            │ created_at   │
                             │            │ updated_at   │
                             │            └──────────────┘
                             │
                             │ FK (guide_id)
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                          tours                               │
├─────────────────────────────────────────────────────────────┤
│ id (PK)              │ external_id        │ bokun_booking_id│
│ bokun_confirmation_code │ title           │ description     │
│ date                 │ time               │ duration        │
│ guide_id (FK)        │ guide_name         │ customer_name   │
│ customer_email       │ customer_phone     │ participants    │
│ price                │ paid               │ payment_status  │
│ payment_method       │ total_amount_paid  │ expected_amount │
│ cancelled            │ cancellation_reason│ rescheduled     │
│ original_date        │ original_time      │ rescheduled_at  │
│ booking_channel      │ booking_reference  │ external_source │
│ needs_guide_assignment│ bokun_data (JSON) │ last_sync       │
│ notes                │ language           │ created_at      │
│ updated_at           │                    │                 │
└──────────────────────┬──────────────────────────────────────┘
                       │
          FK (tour_id) │              FK (guide_id)
                       ▼                    │
           ┌──────────────────┐             │
           │     payments     │◄────────────┘
           ├──────────────────┤
           │ id (PK)          │
           │ tour_id (FK)     │
           │ guide_id (FK)    │
           │ guide_name       │
           │ amount           │
           │ payment_method   │
           │ payment_date     │
           │ payment_time     │
           │ description      │
           │ notes            │
           │ transaction_ref  │
           │ status           │
           │ created_at       │
           │ updated_at       │
           └──────────────────┘

┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│    sessions      │     │   bokun_config   │     │    sync_logs     │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ session_id (PK)  │     │ id (PK)          │     │ id (PK)          │
│ user_id (FK)     │     │ vendor_id        │     │ sync_type        │
│ user_data        │     │ api_key          │     │ start_date       │
│ expires_at       │     │ api_secret       │     │ end_date         │
│ created_at       │     │ api_base_url     │     │ status           │
└──────────────────┘     │ booking_channel  │     │ bookings_found   │
                         │ last_sync        │     │ bookings_synced  │
┌──────────────────┐     │ sync_enabled     │     │ bookings_created │
│ guide_payments   │     │ created_at       │     │ bookings_updated │
├──────────────────┤     │ updated_at       │     │ bookings_failed  │
│ id (PK)          │     └──────────────────┘     │ error_message    │
│ guide_id (FK)    │                              │ triggered_by     │
│ guide_name       │     ┌──────────────────┐     │ duration_seconds │
│ month            │     │ login_attempts   │     │ created_at       │
│ total_amount     │     ├──────────────────┤     │ completed_at     │
│ tour_count       │     │ id (PK)          │     └──────────────────┘
│ payment_count    │     │ username         │
│ created_at       │     │ ip_address       │
│ updated_at       │     │ created_at       │
└──────────────────┘     └──────────────────┘
```

### 5.2 Key Indexes and Constraints

#### Tours Table Indexes

```sql
PRIMARY KEY (`id`)
UNIQUE KEY `bokun_booking_id` (`bokun_booking_id`)
KEY `guide_id` (`guide_id`)
KEY `date` (`date`)
KEY `booking_reference` (`booking_reference`)
KEY `external_source` (`external_source`)
KEY `external_id` (`external_id`)
KEY `cancelled` (`cancelled`)
KEY `rescheduled` (`rescheduled`)
KEY `idx_tours_date_guide` (`date`, `guide_id`)           -- Composite index
KEY `idx_tours_booking_channel` (`booking_channel`)
```

#### Payments Table Indexes

```sql
PRIMARY KEY (`id`)
KEY `tour_id` (`tour_id`)
KEY `guide_id` (`guide_id`)
KEY `payment_date` (`payment_date`)
KEY `status` (`status`)
KEY `idx_payments_date_guide` (`payment_date`, `guide_id`)  -- Composite index
```

#### Tickets Table Indexes

```sql
PRIMARY KEY (`id`)
KEY `museum` (`museum`)
KEY `date` (`date`)
KEY `status` (`status`)
KEY `idx_tickets_museum_date` (`museum`, `date`)           -- Composite index
```

#### Foreign Key Constraints

```sql
-- Tours -> Guides
CONSTRAINT `tours_ibfk_1` FOREIGN KEY (`guide_id`)
  REFERENCES `guides` (`id`) ON DELETE SET NULL

-- Payments -> Tours
CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`tour_id`)
  REFERENCES `tours` (`id`) ON DELETE SET NULL

-- Payments -> Guides
CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`guide_id`)
  REFERENCES `guides` (`id`) ON DELETE SET NULL

-- Guide Payments -> Guides
CONSTRAINT `guide_payments_ibfk_1` FOREIGN KEY (`guide_id`)
  REFERENCES `guides` (`id`) ON DELETE CASCADE

-- Sessions -> Users
CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`)
  REFERENCES `users` (`id`) ON DELETE CASCADE
```

### 5.3 Migration Files Overview

| File | Location | Purpose |
|------|----------|---------|
| `database_schema_updated.sql` | Root | Complete schema with Bokun support |
| `create_login_attempts_table.sql` | `database/migrations/` | Login rate limiting |
| `create_sync_logs_table.sql` | `database/migrations/` | Sync history tracking |
| `add_missing_indexes.sql` | `database/` | Performance optimization |
| `payment_system_migration.sql` | Root | Payment tables setup |
| `payment_system_migration_simple.sql` | Root | Simplified payment migration |
| `create_payment_views.sql` | Root | Payment summary views |
| `bokun_integration.sql` | Root | Bokun-specific columns |
| `import_tickets.sql` | Root | Ticket data import |
| `production_database_migration.sql` | Root | Production migration scripts |
| `update_production_schema.sql` | Root | Schema updates |

---

## 6. CRUD OPERATIONS

### 6.1 Tours Management

**Location:** `tours.php`

| Operation | Method | Key Logic |
|-----------|--------|-----------|
| **Create** | POST | Validates required fields (title, date, time), inserts with payment fields |
| **Read** | GET | Pagination (50/page default, 500 max), filters (date, guide, upcoming) |
| **Update** | PUT | Dynamic SET clause, preserves unchanged fields |
| **Delete** | DELETE | Direct delete, FK cascade handles related records |

**Special Tour Operations:**

```php
// Mark as Paid
PUT /api/tours.php/{id}/paid
Body: { "paid": true }

// Mark as Cancelled
PUT /api/tours.php/{id}/cancelled
Body: { "cancelled": true, "cancellation_reason": "Customer request" }
```

**Rescheduling Detection (during Bokun sync):**

```php
if (($existing['date'] !== $tourData['date']) || ($existing['time'] !== $tourData['time'])) {
    $isRescheduled = true;
    // Save original date/time if first rescheduling
    if (!$existing['rescheduled']) {
        $originalDate = $existing['date'];
        $originalTime = $existing['time'];
    }
}
```

### 6.2 Guides Management

**Location:** `guides.php`

**Create/Update (same endpoint):**

```php
// Detection logic
if (isset($data['id']) && !empty($data['id'])) {
    // UPDATE existing guide
    $stmt = $conn->prepare("UPDATE guides SET name = ?, phone = ?, email = ?,
                            languages = ?, bio = ?, photo_url = ? WHERE id = ?");
} else {
    // INSERT new guide
    $stmt = $conn->prepare("INSERT INTO guides
                            (name, phone, email, languages, bio, photo_url)
                            VALUES (?, ?, ?, ?, ?, ?)");
}
```

**Delete with Constraint Check:**

```php
// Prevent deletion if guide has tours
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM tours WHERE guide_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row['count'] > 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot delete guide with associated tours']);
    exit();
}
```

### 6.3 Payments Management

**Location:** `payments.php`

**Validation Rules:**

```php
$required_fields = ['tour_id', 'guide_id', 'amount', 'payment_method', 'payment_date'];

$valid_methods = ['cash', 'bank_transfer', 'credit_card', 'paypal', 'other'];

// Amount must be positive
if ($input['amount'] <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount must be greater than 0']);
    return;
}
```

**Auto-Assignment Feature:**

```php
// If tour has no guide assigned, update it with the selected guide
if ($tour_result['guide_id'] === null || $tour_result['guide_id'] === '') {
    $update_stmt = $conn->prepare("UPDATE tours SET guide_id = ? WHERE id = ?");
    $update_stmt->bind_param("ii", $input['guide_id'], $input['tour_id']);
    $update_stmt->execute();
}
```

### 6.4 Tickets Management

**Location:** `tickets.php`

**Auto-Migration (creates table if not exists):**

```php
function createTicketsTableIfNotExists($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS tickets (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        location VARCHAR(255) NOT NULL,
        code VARCHAR(255) NOT NULL,
        date DATE NOT NULL,
        time TIME DEFAULT NULL,
        quantity INT(11) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
}
```

### 6.5 Bookings (Bokun Sync)

**Location:** `bokun_sync.php`

**UPSERT Logic:**

```php
// Check if tour already exists
$stmt = $conn->prepare("SELECT id, date, time, rescheduled, original_date, original_time
                        FROM tours WHERE bokun_booking_id = ? OR external_id = ?");

if ($existing) {
    // UPDATE with rescheduling detection
    $stmt = $conn->prepare("UPDATE tours SET
        title = ?, date = ?, time = ?, duration = ?, language = ?,
        customer_name = ?, customer_email = ?, customer_phone = ?,
        participants = ?, booking_channel = ?, total_amount_paid = ?,
        expected_amount = ?, payment_status = ?, paid = ?,
        cancelled = ?, bokun_data = ?, last_sync = ?,
        rescheduled = ?, original_date = ?, original_time = ?
        WHERE id = ?");
} else {
    // INSERT new tour from Bokun
    $stmt = $conn->prepare("INSERT INTO tours (
        external_id, bokun_booking_id, bokun_confirmation_code,
        title, date, time, duration, language,
        customer_name, customer_email, customer_phone, participants,
        booking_channel, total_amount_paid, expected_amount, payment_status, paid,
        external_source, needs_guide_assignment, guide_id, cancelled,
        bokun_data, last_sync, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
}
```

---

## 7. SECURITY ANALYSIS

### 7.1 Strengths

| Area | Implementation | Status |
|------|----------------|--------|
| **SQL Injection Prevention** | Prepared statements with bound parameters throughout | ✅ Strong |
| **XSS Prevention** | JSON-only responses (no HTML rendering) | ✅ Strong |
| **CSRF Protection** | Token-based authentication | ✅ Good |
| **Rate Limiting** | 5 login attempts per IP per 5 minutes | ✅ Implemented |
| **Password Security** | bcrypt hashing via `password_hash()` | ✅ Strong |
| **CORS Configuration** | Origin whitelist in config.php | ✅ Configured |
| **Session Security** | Cryptographically random tokens (64 chars) | ✅ Strong |
| **Error Handling** | Exceptions caught, logged, user-friendly messages | ✅ Good |

### 7.2 Areas for Improvement

| Issue | Risk Level | Current State | Recommendation |
|-------|------------|---------------|----------------|
| Hardcoded DB credentials | Medium | In config.php | Use environment variables via `.env` |
| No HTTPS enforcement | Medium | Relies on server config | Add `Strict-Transport-Security` header |
| Wide CORS origin | Low | `Access-Control-Allow-Origin: *` in some files | Restrict to specific origins |
| Debug display enabled | Low | `display_errors = 1` in some files | Disable in production |
| Legacy auth fallback | Medium | `ALLOW_LEGACY_AUTH` option | Remove plaintext password support |
| No input length limits | Low | Some fields unbounded | Add max length validation |
| No API versioning | Low | Single version | Add `/api/v1/` prefix for future |

### 7.3 Sensitive Data Handling

| Data Type | Storage | Protection |
|-----------|---------|------------|
| User passwords | MySQL `users` table | bcrypt hashed |
| Auth tokens | MySQL `sessions` table | 64-char random, 24hr expiry |
| Bokun API credentials | MySQL `bokun_config` table | Plain text (should encrypt) |
| DB credentials | `config.php` | Plain text (should use env vars) |
| Customer PII | MySQL `tours` table | No encryption (consider at-rest encryption) |

### 7.4 Security Headers (Recommended Additions)

```php
// Add to config.php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Content-Security-Policy: default-src \'self\'');
```

---

## 8. PERFORMANCE CONSIDERATIONS

### 8.1 Current Optimizations

| Optimization | Location | Description |
|--------------|----------|-------------|
| **Client-side caching** | `mysqlDB.js` | 1-minute TTL for tour data |
| **Database indexes** | Schema | Composite indexes on frequently queried columns |
| **Pagination** | All list endpoints | 50 records default, 500 max |
| **Cache busting** | `mysqlDB.js` | Timestamp query parameter |
| **localStorage fallback** | `mysqlDB.js` | Offline support |
| **Lazy loading** | React components | Code splitting via React Router |

### 8.2 Database Query Optimization

**Indexed Queries:**

```sql
-- Tours by date (uses idx_tours_date_guide)
SELECT * FROM tours WHERE date = '2026-01-27' ORDER BY time;

-- Tours by guide (uses guide_id index)
SELECT * FROM tours WHERE guide_id = 5 AND date >= CURDATE();

-- Payments by date range (uses idx_payments_date_guide)
SELECT * FROM payments WHERE payment_date BETWEEN '2026-01-01' AND '2026-01-31';
```

### 8.3 Recommendations for Improvement

| Area | Current State | Recommendation | Priority |
|------|---------------|----------------|----------|
| **API Response Compression** | None | Enable gzip compression | High |
| **Connection Pooling** | New connection per request | Use persistent connections | Medium |
| **Background Jobs** | Synchronous Bokun sync | Queue-based async processing | Medium |
| **CDN for Static Assets** | Served from same server | Use CloudFlare or similar | Medium |
| **Database Query Caching** | None | Redis or MySQL query cache | Low |
| **Image Optimization** | None | Lazy loading, WebP format | Low |

### 8.4 Load Testing Considerations

**Estimated Capacity (based on architecture):**

| Metric | Estimated Limit |
|--------|-----------------|
| Concurrent users | ~100-200 |
| API requests/second | ~50-100 |
| Database connections | ~50 (default MySQL limit) |
| Bokun sync frequency | Every 15 minutes |

---

## 9. SUMMARY

### 9.1 Project Maturity Assessment

| Criterion | Status | Notes |
|-----------|--------|-------|
| Core functionality | ✅ Complete | All CRUD operations working |
| Security basics | ✅ Implemented | SQL injection, XSS, rate limiting |
| Documentation | ✅ Comprehensive | 15+ documentation files |
| Error handling | ✅ Good | Logging + graceful fallbacks |
| API design | ✅ RESTful | Consistent patterns, pagination |
| Database design | ✅ Normalized | Proper indexes and constraints |
| Testing | ⚠️ Manual only | 30+ test scripts, no automated tests |
| CI/CD | ❌ None | Manual deployment process |
| Monitoring | ⚠️ Basic | Sentry for frontend, error_log for backend |

### 9.2 Key Statistics

| Metric | Count |
|--------|-------|
| API Endpoints | 25+ |
| React Components | 14+ |
| React Pages | 9 |
| Database Tables | 10 |
| Database Columns (tours) | 40 |
| Lines of Code (PHP) | ~3,500 |
| Lines of Code (JavaScript) | ~4,000 |
| Documentation Files | 15+ |

### 9.3 Technology Stack Summary

```
┌─────────────────────────────────────────────────────────────┐
│                    TECHNOLOGY STACK                          │
├─────────────────────────────────────────────────────────────┤
│ Frontend:  React 18 + Vite 5 + TailwindCSS 3.4              │
│ Backend:   PHP 8.2 (standalone, no framework)               │
│ Database:  MySQL 8.0                                         │
│ Auth:      Token-based (24hr expiry, bcrypt passwords)      │
│ External:  Bokun API (HMAC-SHA1 authentication)             │
│ Hosting:   Hostinger (production)                            │
│ Monitoring: Sentry (frontend)                                │
└─────────────────────────────────────────────────────────────┘
```

### 9.4 Recommendations Summary

#### High Priority

1. Move database credentials to environment variables
2. Enable HTTPS enforcement with security headers
3. Add automated testing (Jest for frontend, PHPUnit for backend)
4. Remove legacy plaintext password support

#### Medium Priority

1. Implement background job queue for Bokun sync
2. Add API response compression
3. Set up CI/CD pipeline with GitHub Actions
4. Add database connection pooling

#### Low Priority

1. Add API versioning (`/api/v1/`)
2. Implement Redis caching layer
3. Add WebSocket support for real-time updates
4. Consider TypeScript migration for frontend

---

## Appendix A: File Reference

### Backend API Files

| File | Lines | Purpose |
|------|-------|---------|
| `config.php` | ~150 | Database connection, CORS, environment detection |
| `tours.php` | ~530 | Tours CRUD operations |
| `guides.php` | ~175 | Guides CRUD operations |
| `payments.php` | ~435 | Payment transactions |
| `tickets.php` | ~285 | Ticket inventory |
| `auth.php` | ~170 | Authentication |
| `BokunAPI.php` | ~450 | Bokun API client |
| `bokun_sync.php` | ~660 | Sync orchestration |
| `bokun_webhook.php` | ~160 | Webhook handlers |
| `BaseAPI.php` | ~100 | Abstract base class |

### Frontend Files

| File | Lines | Purpose |
|------|-------|---------|
| `App.jsx` | ~150 | Main app with routes |
| `AuthContext.jsx` | ~115 | Auth state management |
| `mysqlDB.js` | ~470 | API client service |
| `Dashboard.jsx` | ~375 | Dashboard component |
| `Tours.jsx` | ~600 | Tours page |
| `ModernLayout.jsx` | ~300 | Layout component |

---

**Report Generated:** January 27, 2026
**Auditor:** Claude Opus 4.5
**Version:** 1.0
