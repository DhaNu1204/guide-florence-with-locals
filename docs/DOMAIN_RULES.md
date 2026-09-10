# Domain rules & hard-won facts

> Extracted from the July 2026 CLAUDE.md. These are the business rules and Bokun/Twilio/P&L facts that must not be broken. Read the section relevant to the area you are touching.

## Tour Grouping System (Feb 2026)

### Business Rules
- Groups bookings that are same product + date + time
- Max 9 PAX per group (Uffizi museum rule)
- Auto-grouping runs after every Bokun sync
- Manual merges are never touched by auto-grouping (`is_manual_merge=1`)
- Assigning guide to group propagates to all tours in group
- 1 group = 1 payment unit (not per-booking)

### Database
- **`tour_groups`** table: id, group_date, group_time, display_name, guide_id, max_pax (9), is_manual_merge
- **`tours.group_id`** FK to tour_groups
- SQL pattern: `IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit`

### Integrity
- All group operations use database transactions (begin/commit/rollback)
- Advisory lock: `GET_LOCK('auto_group', 10)` prevents concurrent auto-grouping
- Orphan cleanup: deletes groups with no member tours

### Frontend
- `TourGroup.jsx`: Expandable row, guide edit, unmerge/dissolve, DnD target
- `TourGroupCardMobile.jsx`: Mobile expand/collapse, PAX fraction (X/9)
- Tours.jsx: HTML5 drag-and-drop, selection mode for mobile merge
- `groupedTours` memo: `periodGroup.items` array (tours + `{_isGroup, group}` objects)

### API
| Method | Endpoint | Action |
|--------|----------|--------|
| GET | `/api/tour-groups.php` | List groups |
| POST | `?action=auto-group` | Auto-group by product+date+time |
| POST | `?action=manual-merge` | Merge specific tour IDs |
| POST | `?action=unmerge` | Remove tour from group |
| PUT | `/{id}` | Update group (guide propagates) |
| DELETE | `/{id}` | Dissolve group |

## Guide Reports (Jun 2026)

Read-only month-end invoice verification — lists the tours a guide actually performed so the owner can check them against the guide's monthly invoice. **Touches no payment logic.** Group-aware (1 group = 1 tour unit), excludes cancelled tours and ticket products (same `products.product_type='ticket'` exclusion as guide-payments.php). Title-based category classification (Combo / Uffizi / Pitti / Accademia / Other) mirrors how guides reconcile on WhatsApp.

- **Backend**: `guide-tour-report.php` — `classifyTourCategory()` keyword rules (uffizi; accademia incl. "david"; pitti incl. boboli/palatina/palatine; 2+ museums = Combo)
- **Frontend**: `src/pages/GuideReports.jsx` (sidebar: Guide Reports), service `getGuideTourReport()` in mysqlDB.js
- **Exports**: PDF (jsPDF + autoTable) and Excel-compatible CSV (no xlsx dep)

### API
| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/api/guide-tour-report.php` | Auth required (read-only). Params: optional `guide_id`; `period=YYYY-MM` **or** `start=YYYY-MM-DD&end=YYYY-MM-DD`. With `guide_id`: returns `guide_info`, `total_tours`, `tours[]` (date/time/title/category/composition/composition_label) + `summary_by_category` (Combo/Uffizi/Pitti/Accademia/Other/**Mixed**). Without `guide_id`: month overview `guides[]` (guide_id/guide_name/total_tours/by_category incl. Mixed). Group units are classified by MEMBER bookings (see "Mixed merged groups"). |

## Guide Availability Requests (Jun 2026)

WhatsApp-based "ask a guide if they're available" flow. **Touches no payment logic.** The owner picks a guide for an unassigned tour (picker lists guides who speak the tour's language first), gets a pre-filled WhatsApp message + secret link, and the guide accepts/declines on a no-login page. Accepting assigns the guide (with double-booking + already-taken guards).

- **DB**: `availability_requests` table — self-provisioned via `CREATE TABLE IF NOT EXISTS` on first request to `guide-requests.php` (columns: id, tour_id, guide_id, token UNIQUE, status ENUM('pending','accepted','declined','cancelled','expired'), created_at, responded_at).
- **Frontend**: `src/components/AskGuideModal.jsx` (reusable; used on Tours page + Dashboard), public page `src/pages/GuideRespond.jsx` at `/respond/:token` (Italian, mobile, plain `fetch` — NOT the axios instance, so no Bearer token is sent). Services in mysqlDB.js: `createGuideRequest`, `getGuideRequests`, `getOpenGuideRequests`, `getRecentGuideResponses`.
- **Double-booking guard** also enforced on the tours.php PUT guide assignment (HTTP 409 `guide_double_booked` unless `force=true`).

### API — `guide-requests.php` (conditional auth)
A `token` query param routes to the **PUBLIC guide branch** (no auth); otherwise it's an **OWNER** request requiring `Middleware::requireAuth`.

| Mode | Method | Endpoint | Notes |
|------|--------|----------|-------|
| Owner | POST | `/api/guide-requests.php` `{tour_id, guide_id}` | Create (or reuse pending) request → `{id, token, status, link, message}` |
| Owner | POST | `{action:'cancel', id}` | Cancel a request |
| Owner | GET | `?tour_id=X` | List a tour's requests |
| Owner | GET | `?action=open` | Pending/declined requests for upcoming tours (for persistent badges) |
| Owner | GET | `?action=recent&days=N` | Recently accepted/declined responses (dashboard panel) |
| Public | GET | `?token=XYZ` | Minimal tour logistics only (date/time/title/language/participants/meeting_point) — **never customer PII** |
| Public | POST | `?token=XYZ` `{action:'accept'\|'decline'}` | Accept assigns the guide (double-booking + already-taken guards, expires sibling pendings); decline records it |

## Guide WhatsApp Reminders (Jun 2026)

Automatic Twilio WhatsApp reminder to the **assigned guide ~1 hour before each tour** so guides don't forget. **Touches no payment logic.** Built and live (`TWILIO_REMINDERS_ENABLED=true`).

### How it works
- **`twilio_reminders.php` → `reconcileGuideReminders($conn)`** schedules a **Twilio Scheduled Message** (Messaging Service + `ContentSid`, `ScheduleType=fixed`, `SendAt = tour start − TWILIO_GUIDE_REMINDER_LEAD_MIN`) for every tour that is **guide-assigned, non-cancelled, non-ticket, and starts within the next 7 days**. Tour start is computed in **Europe/Rome**; `SendAt` is sent to Twilio in UTC.
- On change (tour time moved, guide reassigned, tour cancelled/unassigned) it **cancels and reschedules / cancels** the existing scheduled message so the booked reminder always matches live data. Idempotent — safe to run repeatedly.
- **`guide_reminders` table** (self-provisioned via `CREATE TABLE IF NOT EXISTS`) tracks one row per tour: `tour_id` (UNIQUE), `guide_id`, `twilio_sid`, `send_at_utc`, `status` ENUM('scheduled','canceled','sent','failed'), `last_error`.

### Why reconcile on every sync (not cron)
- **Twilio's scheduled-message window is max 7 days out.** So reminders can't all be booked up front — they're **reconciled on EVERY sync**: hooked into the end of `syncBookings()` and into `tours.php` PUT (after a guide is assigned), **both exception-isolated** (a reminder failure can never break booking sync or guide assignment). A tour gets its reminder scheduled once it enters the 7-day window; **Twilio then fires the send at the exact time** — no dependence on Hostinger cron (which doesn't reliably fire on this host).

### Config (`.env`, read via EnvLoader / `config.php`)
- `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`
- `TWILIO_MESSAGING_SERVICE_SID` — `MG…` (see .env) (WhatsApp sender `whatsapp:+1…` attached)
- `TWILIO_GUIDE_REMINDER_CONTENT_SID` — `HX…` (see .env) (Meta-**approved** UTILITY template)
- `TWILIO_GUIDE_REMINDER_LEAD_MIN=60`
- `TWILIO_REMINDERS_ENABLED=true`

The Auth Token is used only for the HTTP Basic auth header — never logged or echoed.

### Template (Italian, approved UTILITY)
> `Ciao {{1}}, promemoria del tuo tour di oggi: {{2}} alle {{3}}. Grazie!`
>
> `{{1}}` = guide first name, `{{2}}` = tour title, `{{3}}` = start time (HH:MM).

### Failures
- A schedule failure (e.g. an **invalid guide phone number**) is recorded in `guide_reminders.last_error` with `status='failed'` and **swallowed** (never throws to the caller). It's **retried automatically on the next reconcile** once the underlying data is fixed (e.g. the guide's phone corrected in the Guides page). Phone normalization deliberately does **not** guess a country code — Italian mobiles stored without `+39` are treated as unusable until corrected.

### Delivery fix (Jun 2026, dd3d8d3) — WANTED vs CREATABLE
The original reconcile **self-cancelled every reminder ~12 min before SendAt**: Twilio's 15-minute scheduling floor (`minSendAt = now + 15min`) wrongly gated **retention** as well as creation, so a tour inside the floor dropped out of the kept set and the removal pass cancelled its already-booked message. Fixed by splitting the concepts in a pure, unit-tested `reminderPlan()`:
- **WANTED** (valid phone + future start within the 7-day window) alone decides **retention** — imminent reminders are never cancelled.
- **CREATABLE** (`sendAt >= now + 15min`) only gates **new** scheduling.
- The removal pass cancels only reminders that are **not wanted** (tour cancelled, guide unassigned/changed, time moved).
Also in the same fix: **one reminder per DEPARTURE** — the candidate query collapses each group to its lowest active tour id, so a grouped guide gets ONE WhatsApp, not one per booking; **group-level guide assignment** (tour-groups.php `updateGroup`) propagates `guide_id` to member tours and reconciles (exception-isolated); and the **unassign path** reconciles too (see "Guide unassign fix" below), cancelling the guide's reminder when they're removed from a tour/group.

## Tour Classification (Jun 2026)

`public_html/api/tour_classification.php` is the **single source of truth** for private-tour classification and per-product PAX capacity. Pure logic — no DB access, no side effects, no payment logic — safe to `require_once` anywhere.

### Functions
- **`isPrivateBooking($productId, $rateId, $rateTitle)`** → bool
- **`getMaxPaxForTitle($title)`** → 9 for `uffizi` (incl. Uffizi+Accademia combos), 19 for `accademia`/`david`, else 9
- **`bokunRateInfo($bokunData)`** → `[rateId, rateTitle]` (accepts a decoded array or JSON string)
- **`computePaxBreakdown($bokunData, $fallbackParticipants)`** → `[adults, children, infants]` from `productBookings[0].fields.priceCategoryBookings` (sums quantity by `pricingCategory.ticketCategory` ADULT/CHILD/INFANT, title-keyword fallback; else all-adults from participants). Powers the server-computed `pax_adults`/`pax_children`/`pax_infants` fields.

### Private rules (the constants live in this file)
```php
FULLY_PRIVATE_PRODUCTS = [809837, 828971, 850642, 878643, 911547, 945194, 962886, 947299, 1145330, 1233544, 1115569];
MIXED_PRIVATE_RATES    = [962885 => [2266785], 1130528 => [2244449]];
```
- `productId` ∈ FULLY_PRIVATE_PRODUCTS → **private**.
- `productId` is a MIXED key → private only if `(int)rateId` is in that product's list; if rateId is empty/missing, fall back to `rateTitle` (trimmed, lowercased) containing `private`.
- else → not private.

### Where the values come from in `bokun_data`
- **rateId** = `productBookings[0].fields.rateId`  (⚠️ NOT top-level `productBookings[0].rateId`, which doesn't exist)
- **rateTitle** = `productBookings[0].rateTitle` (free text — has trailing-space/casing variants; trim + lowercase before matching)

### ➜ To add a new private product or rate
Edit the constants in `tour_classification.php` (add the product id to `FULLY_PRIVATE_PRODUCTS`, or add/extend the `MIXED_PRIVATE_RATES` rate-id list), then **re-run a backfill + regroup** so existing rows pick it up: a one-off CLI that loops `tours.bokun_data`, recomputes `is_private` via the helper, then calls `autoGroupAfterSync($conn, start, end)`. New bookings are classified automatically on the next sync.

## Tour Grouping (Jun 2026 — product-aware)

`autoGroupAfterSync($conn, $startDate, $endDate)` in `bokun_sync.php` (runs at the end of every `syncBookings`):

- **Group key = `product_id | date | HH:MM`** (was normalized title) — same product at the same departure groups together even when sold under different channel titles (e.g. 962885's "Uffizi & Accademia Walking Tour…" + "Uffizi, David Tour & Gelato…").
- **Excluded from auto-grouping** (left standalone, `group_id` NULL): `cancelled = 1`, `is_private = 1`, or `product_id IS NULL`. **Private tours are never auto-grouped.**
- **Rebuild model**: detaches all auto-group tours in range (manual merges, `is_manual_merge = 1`, are **untouched**), drops orphaned auto groups, then regroups from the candidates. Cancelled/private tours get `group_id` cleared.
- **`display_name`** = the **most-frequent title** among the group's bookings (tie → the title with most PAX), via `pickDisplayTitle()`.
- **Per-product capacity**: `getMaxPaxForTitle(display_name)` (Tour Classification) — sub-groups split when active PAX would exceed it; stored on `tour_groups.max_pax`.

### `tours.is_private` column
- `TINYINT(1) NOT NULL DEFAULT 0` + `idx_tours_is_private`. **Self-provisioned** by `ensureIsPrivateColumn($conn)` in `bokun_sync.php` and by `tours.php` (same `SHOW COLUMNS` guard pattern as `product_id`).
- Set on **every** insert/update in `bokun_sync.php` via `isPrivateBooking()`. Returned to the frontend through the existing `SELECT t.*` (no SELECT change).
- **Frontend**: purple **Private** badge (`bg-purple-100 text-purple-800`) on Tours rows + `TourCardMobile` (private tours render standalone with the normal guide-assign UI + Ask button). Cancelled bookings are excluded from PAX/booking/FULL counts (`src/utils/tourCapacity.js` — `getMaxPax`, `countActivePax`, `countActiveBookings`). Assigned + non-cancelled tours get a light-green background (priority: cancelled red > assigned green > default).

## Tours date filter (Jun–Jul 2026)

`src/components/DateFilter.jsx` — a themed in-app calendar replaces the native `<input type="date">` and the loose period buttons; `Tours.jsx` just renders `<DateFilter {...state/setters} />` (all existing filter state reused: `filterDate`, `showUpcoming`, `showPast`, `showDateRange`, `rangeStartDate`, `rangeEndDate`).
- **Trigger** button: calendar icon + selected date `EEE, d MMM yyyy`; "Pick a date" when in Upcoming/Past/Range mode; terracotta border/ring when open.
- **Popover calendar**: date-fns 6-row grid (`startOfMonth/endOfMonth/startOfWeek/endOfWeek/eachDayOfInterval`); selected day = terracotta filled, today = inset terracotta ring, out-of-month muted, hover stone. Outside-click + Escape close.
- **Month arrows land on the 1st** (`startOfMonth(addMonths/subMonths(filterDate||today, 1))`) and keep the popover open (owner's preferred behavior).
- **Segmented control (Jul 2026)**: **Today / Tomorrow / Upcoming / Date Range** (active segment terracotta). "Past 40 Days" was removed (the `showPast` state/plumbing in Tours.jsx intentionally remains, unused). **Tomorrow** = `setFilterDate(startOfDay(addDays(today, 1)))` + clear all flags (same single-date mode as Today); active when the selected single date is tomorrow. Range mode shows the two date inputs (`end >= start`).

## Tours counts & Category Summary (Jul 2026)

- **Cancelled bookings are excluded from ALL counts**: not just group PAX/booking/FULL badges (`countActivePax`/`countActiveBookings` in `src/utils/tourCapacity.js`), but also the **day-header, period-header, and Summary tour counts**. Shared `computeItemStats(items)` in Tours.jsx returns `{activeTours, cancelledCount, pax}`; counts render as "N tours · M PAX" with a muted "**· N cancelled**" suffix only when N > 0.
- **Category Summary panel** on the Tours page Summary card: buckets each ACTIVE departure once via `tourCategory(title)` (`src/utils/tourCapacity.js` — mirrors backend `classifyTourCategory`: uffizi; accademia incl. david; pitti incl. boboli/palatina/palatine; 2+ museums = Combo; else Other). Private departures mirror shared categories exactly: **Combo, Uffizi, Accademia, Pitti, Other, Private Combo, Private Uffizi, Private Accademia, Private Pitti, Private (other)** — 0-buckets hidden, Private tiles tinted purple.

## PAX breakdown — adults/children/infants (Jul 2026)

- **Server-computed** so it works for BOTH standalone rows and group member rows (group members come from tour-groups.php `getGroupTours()` which doesn't return `bokun_data`): `tours.php` GET and tour-groups.php member rows include **`pax_adults` / `pax_children` / `pax_infants`** per tour, via `computePaxBreakdown()` in `tour_classification.php`.
- **Frontend** `getPaxBreakdown(tour)` (tourCapacity.js) PREFERS the server `pax_*` fields, falls back to parsing `bokun_data`, then to `participants` as all-adults. Display (muted, only when children/infants > 0): appended to PAX cells/rows via `formatBreakdown`/`aggregateBreakdown` — e.g. "5 adults, 1 child".
- **BookingDetailsModal.jsx compacted** (smaller header/typography/spacing; the oversized Adults/Children/Total cards became a compact inline chip row — all fields kept).

## Guide unassign fix (Jul 2026)

Clearing a guide (select "Unassigned") sends an explicit **`guide_id: null`** — and PHP `isset()` is FALSE for a present-but-null key, so the dynamic UPDATE builders skipped the field and returned 400 "No fields to update". **Fixed with `array_key_exists()`** (not `isset()`) at every guide_id detection point in tours.php PUT and tour-groups.php `updateGroup` (SET clause, `propagateGuideToTours` — NULL now propagates to member tours — and the reminder-reconcile triggers, so unassign cancels the guide's WhatsApp reminder). Rule: **"field omitted" = don't touch; "field present but null/empty" = SET NULL.** The double-booking guard only fires for non-null values, so unassigns are never blocked. ⚠️ Apply the same `array_key_exists` pattern to any nullable field in a `$setFields` dynamic-UPDATE builder.

## Mixed merged groups (Jul 2026)

A merged group can span more than one tour category (e.g. 2 Combo bookings + 1 Uffizi) — this matters for guide pay, so it is flagged everywhere (display/report only, NO payment calculation touched):
- **Tours page** (TourGroup.jsx desktop + TourGroupCardMobile.jsx): expanded member rows show each booking's own category via `tourCategory(booking.title)` as a badge (new "Type" column on desktop; inline on mobile) — Combo styled gold. The group HEADER computes the distinct category set over NON-cancelled members; >1 distinct → gold **"Mixed" badge** next to the title with a tooltip breakdown ("Combo x2, Uffizi x1").
- **Guide Reports** (guide-tour-report.php): group units are classified by their **member bookings** via `buildComposition($titles)` → `[category, composition, composition_label]` — 1 distinct member category → that category; >1 → **"Mixed"**. A mixed group stays ONE tour unit, increments only the **Mixed** bucket (order: Combo, Uffizi, Pitti, Accademia, Other, Mixed; bucket sum = total_tours), and its `tours[]` row carries `composition` (`[{category,count},…]`) + `composition_label` ("Combo ×2, Uffizi ×1"). Same member-based classification in `getAllGuidesOverview` (by_category gains Mixed). Frontend GuideReports.jsx shows a gold Mixed badge + label; **PDF/CSV export** "Mixed (Combo ×2, Uffizi ×1)" and include Mixed in summaries.

## Daily P&L Tracker (Jul 2026) — ADMIN ONLY

Per-day/week/month profit & loss over tour units. **Touches NO payment logic** — reads tours/groups read-only; writes ONLY to its own self-provisioned tables.

### Backend — `api/pnl.php` (`Middleware::requireRole($conn, 'admin')`)
| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `?date=YYYY-MM-DD` | Per-tour-unit rows (unit key `g<group_id>`/`t<id>`, same as payments) + day totals + settings |
| GET | `?start=&end=` | Range (max 92 days): per-day totals, grand totals, `by_category[]` (Combo/Uffizi/Accademia/Pitti/Borghese/Mixed/Other/Tickets with units/pax/net/cost/profit), monthly overhead, profit_after_overhead |
| GET/POST | `?action=settings` | Whitelisted key/value rates (see below) |
| POST | `?action=costs` | Per-unit override upsert — `array_key_exists` pattern, explicit null CLEARS an override; `outsourced` stored strictly 0/1 |

- **Tables (self-provisioned)**: `pnl_settings` (key/value DECIMAL), `pnl_tour_costs` (per-unit overrides: 6 cost fields + revenue_override + outsourced TINYINT + notes; `outsourced` column added via SHOW COLUMNS guard for older installs).
- **Revenue extraction** per booking from stored `bokun_data` (checks top level, `productBookings[0]`, `activityBookings[0]`): `resellerInvoice` (total/totalCommission/totalSansCommission) → `sellerCommission` + `customerInvoice.total`/`totalPrice` → channel-% estimate from settings (flagged `estimated`, "~" in UI; direct/Bokun/website channels = 0%). Cancelled bookings excluded everywhere.
- **Auto-cost precedence for guide cost**: outsourced (→ €0 + `outsource_fee` in Other, tickets KEPT) > ticket product (€0) > `is_private` **per-category private rate** (`pnlPrivateGuideRate`: combo €240/4h, uffizi €120/2h, accademia €90/1.5h, pitti/other €120/2h) > Mixed group (highest member-category rate) > category rate. Museum tickets = per museum mentioned in each BOOKING's title × adult/child PAX (`computePaxBreakdown`); **Uffizi bookings with time ≥ 16:00 use `ticket_uffizi_*_pm`** (afternoon rate since 1 Jan 2026). Radio/gelato per person (gelato only when a member title contains "gelato").
- **Settings keys**: `guide_rate_{combo,uffizi,accademia,pitti,other}`, `guide_rate_private_{combo,uffizi,accademia,pitti,other}`, `ticket_{uffizi,accademia,pitti,borghese}_{adult,child}` + `ticket_uffizi_{adult,child}_pm`, `radio_per_person`, `gelato_per_person`, `outsource_fee`, `staff_monthly`, `office_monthly`, `other_monthly`, `comm_{getyourguide,viator,headout,default}`. Defaults apply only to never-saved keys (DB rows win; the obsolete single `guide_rate_private` row is ignored). Owner's rule: guides = €60/h; hours vary by tour type (shared combo 3.5h; private: combo 4h, uffizi 2h, accademia 1.5h, pitti/other 2h).

### Frontend — `src/pages/DailyPnL.jsx` (route `/daily-pnl`, sidebar item `adminOnly`)
Day|Week|Month views. Day = sectioned cards (Guided Tours grouped by category, Tickets & Audio Guides, collapsed Cancelled) with EditableChip inline overrides (terracotta = manual, ↺ reset) and **CostDetailModal** (tap card → formula breakdown + editable fields + agency toggle + notes + live profit; chips hidden on mobile, modal is bottom sheet <sm). Week/Month = CategoryTiles ("Profit by product") + per-day table (overhead footer month-only). Services in mysqlDB.js: `getPnlDay/getPnlRange/getPnlSettings/savePnlSettings/savePnlCosts`.

## PWA (Jul 2026)

Installable on iOS/Android (Safari → Share → Add to Home Screen → "FwL Tours").
- `public/manifest.webmanifest` (standalone, portrait, #C75D3A/#FAF6F0), `public/icons/` (192/512/512-maskable/apple-touch 180 — terracotta Duomo).
- `public/sw.js` — conservative: **NEVER intercepts `/api/`**; navigations network-first (cached index.html only as offline fallback, so deploys appear on next load); `/assets/*` stale-while-revalidate; bump `CACHE_VERSION` to force-clear.
- Registration in `src/main.jsx` gated on `import.meta.env.PROD` (dev HMR unaffected). `index.html` has the iOS meta tags.
- `public/.htaccess` adds manifest MIME + sw.js no-cache — **remember deploy.sh does not ship .htaccess** (see deployment warnings).

## Dashboard compaction (Jul 2026)

Dashboard sections collapsed by default with per-section "Show all (N) ▾ / Show less ▴" (44px targets): needs-guide alert previews 3, recent guide responses 3, Upcoming Tours 5, Needs Attention 5. Display-only (data/sort/caps unchanged). Tests: `src/components/__tests__/Dashboard.collapse.test.jsx` (4 interaction tests; suite now 91).

## Guide phone validation (Jun 2026)

The Add/Edit Guide form (`src/pages/Guides.jsx`) requires an **international** phone so the WhatsApp tour reminder can actually send. `isValidGuidePhone(phone)` strips spaces/dashes/dots/parens then requires `/^(\+|00)\d{8,15}$/` (must start with `+` or `00` country code, then 8–15 digits; empty = invalid). On an invalid number the form shows an inline terracotta warning + a toast and **blocks save**. Bare local numbers (e.g. `3392863290`) and emails in the phone field are rejected. Pairs with the reminder backend, which deliberately won't guess a country code.

## Group-Aware Payment System (Feb 2026)

### SQL Pattern
```sql
-- Tour unit: group or individual
IF(t.group_id IS NOT NULL, CONCAT('g', t.group_id), CONCAT('t', t.id)) AS tour_unit

-- Unpaid: GROUP BY tour_unit HAVING MAX(p.id) IS NULL
-- Paid: GROUP BY tour_unit with INNER JOIN payments
```

### Duplicate Prevention
- `payments.php` checks if any tour in same group already has a payment → HTTP 409
- `force_group_payment: true` bypasses group duplicate check
- `force_payment: true` bypasses per-tour duplicate check

### Ticket Filtering in Payments
Uses `NOT EXISTS (SELECT 1 FROM products pr WHERE pr.bokun_product_id = t.product_id AND pr.product_type = 'ticket')` — replaced 45 lines of `NOT LIKE` keyword matching across 9 locations.

## Product Classification System (Feb 2026)

### Overview
Classifies Bokun products as `tour` or `ticket` via a dedicated `products` table, replacing fragile keyword-based filtering (`NOT LIKE '%Entry Ticket%'` etc.) with reliable product ID lookups.

### Database
- **`products`** table: `bokun_product_id` (PK), `title`, `product_type` ENUM('tour','ticket'), timestamps
- **`tours.product_id`** column: FK to products, extracted from `bokun_data` JSON
- **Auto-migration**: `tours.php` auto-creates table/column on first request via `SHOW TABLES`/`SHOW COLUMNS` guards
- **Backfill**: One-time `JSON_EXTRACT` from `bokun_data` populates `product_id` for existing tours

### Known Ticket Product IDs
`809838`, `845665`, `877713`, `961802`, `1115497`, `1119143`, `1162586`

### Query Parameter
- `?product_type=tour` (default) — excludes tickets: `WHERE (pr.product_type = 'tour' OR t.product_id IS NULL)`
- `?product_type=ticket` — tickets only: `WHERE pr.product_type = 'ticket'`
- `?product_type=all` — no filter

### Sync Integration
- `BokunAPI.php` extracts `product_id` from `productBookings[0].product.id`
- `bokun_sync.php` auto-registers new products via `INSERT IGNORE INTO products`
- New products default to `product_type='tour'`

### Idempotent Ticket Classification (Mar 2026 fix)
- Known ticket IDs are enforced on **every request** to `tours.php` via `INSERT ... ON DUPLICATE KEY UPDATE`
- Previously, the classification only ran once during initial migration — products synced later were never reclassified
- The idempotent query handles all cases: new products inserted as `'ticket'`, mis-classified products corrected, already-correct rows are a no-op
- **Root cause of Borghese bug**: Product 1162586 was synced after the one-time migration, so `INSERT IGNORE` registered it as `'tour'`

### Files Modified
- **Backend**: `tours.php` (auto-migration + query param + idempotent classification), `bokun_sync.php` (product registration + product_id in INSERT/UPDATE), `BokunAPI.php` (product_id extraction), `guide-payments.php` (9x NOT LIKE → NOT EXISTS)
- **Frontend**: `Tours.jsx` (removed `filterToursOnly()`), `PriorityTickets.jsx` (added `product_type: 'ticket'`), `mysqlDB.js` (product_type param passthrough)
- **Migration**: `database/migrations/create_products_table.sql`

### Classify a New Product as Ticket
Add the product ID to the known ticket list in `tours.php` (the `INSERT ... ON DUPLICATE KEY UPDATE` statement near line 148). This ensures the classification is enforced on every request. For an immediate one-off fix:
```sql
UPDATE products SET product_type = 'ticket' WHERE bokun_product_id = <id>;
```

## GYG Participant Names (Feb 2026)

- **Source**: `productBookings[0].specialRequests` in GYG bookings
- **Format**: `"Traveler 1:\nFirst Name: X\nLast Name: Y\n..."`
- **Regex**: `/Traveler\s+(\d+):\s*\n?First Name:\s*(.+?)\s*\n?Last Name:\s*(.+?)(?:\n|$)/i`
- **Normalization**: `mb_convert_case(mb_strtolower(...), MB_CASE_TITLE)`
- **Storage**: `tours.participant_names` TEXT column (JSON array)
- **Frontend**: `ParticipantNamesCompact` — "First Last +N more" expandable
- **Backfill**: `bokun_sync.php?action=backfill-names`

## Bokun API Integration

### Authentication
- HMAC-SHA1 signature: `base64(hmac_sha1(Date + AccessKey + Method + Path, SecretKey))`
- Credentials encrypted with AES-256-CBC in `bokun_config` table

### Sync Mechanics
- Both SUPPLIER (OTA) and SELLER (direct) roles queried
- 200 bookings/page, up to 10 pages (2,000 max)
- Deduplicates by booking ID across roles
- Auto-groups after every sync
- Rate limited: 10/min

### Data Extraction
- **Time**: `startTimeStr` (local time, not UTC conversion)
- **Language**: From booking notes, rate title, or product title
- **Names**: Parsed from GYG special requests
- **Channel**: From `channel.title` or `seller.title`

### Auto-Sync Triggers
- On app startup (if stale > 15 min)
- Every 15 minutes (periodic)
- On app focus/visibility change
- Manual trigger (admin only)

## Bokun Sync Architecture (Jun 2026)

Hard-won facts from the sync overhaul (2026-06-22/23). The server-side mechanism is the **real-time webhook**, not Hostinger cron.

### Pagination — paginate by PAGE FULLNESS, not totalHits
`BokunAPI.php` `getBookings()`: Bokun's `totalHits` **under-reports** the true count (e.g. says 787 when there are 987). The old `((page+1)*pageSize) < totalHits` check stopped one page early and **silently dropped page-4+ bookings** (incl. cancelled/rescheduled ones, which then never updated). Now: **continue while a page returns a full `pageSize`** (a short page is the last one), with a 10-page (2,000-booking) safety cap.

### Sync window
- `DEFAULT_SYNC_DAYS` reduced **120 → 60**. The 120-day pass ran **145–267s** and got killed under rate/time limits, so syncs never completed. 60 days completes reliably.
- `FULL_SYNC_DAYS` **365** kept for occasional deep/manual sync.

### Cron — do NOT rely on it
- **Hostinger cron does not reliably fire on this account** — jobs added in hPanel never triggered. The webhook is the server-side freshness mechanism.
- `bokun_cron.php` (CLI entry point) had a **latent parse error since creation**: a `*/15` crontab example inside the docblock contained `*/`, closing the `/** */` comment early → file unparseable. Fixed; the schedule comment is now written as `0,15,30,45`.

### Webhook (`bokun_webhook.php`) — real-time, body-driven
- **History**: previously **500'd on EVERY call** — the `bokun_webhook_logs` table never existed in prod and `logWebhook()` ran *before* the try/catch, so the request died at the logging step before any handler. Now: **self-provisions `bokun_webhook_logs`** (CREATE TABLE IF NOT EXISTS, same pattern `tours.php` uses for `products`), **non-fatal logging**, and **always returns HTTP 200** (so Bokun never enters a retry storm).
- **Payload shape**: Bokun sends an **EMPTY `X-Bokun-Topic` header** (and empty `X-Bokun-Booking-Id`) — the header-based topic switch never matched. The event is the **full booking object in the BODY**: top-level `bookingId` / `status` / `confirmationCode` / `externalBookingReference` + **`activityBookings[]`** with **`startDateTime` (epoch ms)** / `startTime` / `date` / `dateString`. ⚠️ This is the **booking-detail shape**, NOT the `productBookings[]` / `startTimeStr` shape the polling/search path uses — do not assume they're interchangeable.
- **Flow**: read body → extract unique affected date(s) (Europe/Rome) from `activityBookings` → for each date call **`syncBookings(D, D, 'webhook', bookingId)`** through the **proven path** (reuses transform / match / reschedule / cancel — booking-search includes CANCELLED — product registration / auto-grouping). Marks the log row `processed=1` on success.
- **Dateless events skip the sync** (capture + 200 fast). Running a multi-day fallback could exceed the gateway timeout (504 → Bokun retries); the in-app 15-min sync catches anything a dateless event would miss.

### `bokun_sync.php` as a LIBRARY
Define `BOKUN_SYNC_LIB` **before** `require`-ing the file to expose `syncBookings()` without running the endpoint: the guard `if (php_sapi_name() !== 'cli' && !defined('BOKUN_SYNC_LIB'))` skips **both** the web auth/routing block **and** the top-level `applyRateLimit('bokun_sync')`. Used by the webhook (which keeps its own `webhook` 30/min limit, so no double-charge / 429-abort on bursts). CLI cron skips the same block via `php_sapi_name() === 'cli'`. Direct HTTP access stays fully authenticated + rate-limited.

### Duplicate-booking race fix (Jul 2026)
A real incident (GET-98758588 inserted twice, both rows `created_at` in the same second) proved the upsert's check-then-insert (`SELECT … WHERE bokun_booking_id = ? OR external_id = ?` → INSERT) races when two syncs run concurrently — e.g. the 15-min in-app timer firing on two open tabs/devices at once. Fixes in `bokun_sync.php`:
- **`UNIQUE KEY uniq_tours_external_id (tours.external_id)`** — the DB-level backstop. Self-provisioned by `ensureExternalIdUniqueIndex()` at the start of every `syncBookings()` (SHOW INDEX guard). NULLs are allowed (manual tours have no external_id — MySQL unique indexes permit multiple NULLs). If legacy duplicate rows exist the ALTER fails **non-fatally** (try/catch — mysqli strict mode THROWS on failed queries, a plain `if (!$conn->query(...))` is not enough) and the sync continues without the index until duplicates are cleaned up.
- **Insert path is `INSERT … ON DUPLICATE KEY UPDATE`**: a race loser becomes a light update (`bokun_data`, `last_sync`, `updated_at`) instead of a duplicate row. `id = LAST_INSERT_ID(id)` in the ODKU clause keeps `$conn->insert_id` valid for the follow-up `is_private` write. Created-vs-updated stats use `$conn->affected_rows` (1 = insert, 2 = duplicate-key update), captured IMMEDIATELY after execute — any later statement resets it.
- ⚠️ The duplicated row pair also revealed the follow-on failure mode: later syncs only ever update the FIRST match, so the loser row froze at insert time, and the auto-grouper then **grouped the booking with its own duplicate** (inflating PAX and P&L revenue). If duplicates ever reappear, delete the row with the stale `last_sync` (check payments/guide_reminders/availability_requests references first) and re-run `autoGroupAfterSync()` for the affected date.

### `sync_logs` — self-provisioned (Jul 2026)
`sync_logs` never existed in prod, and `logSyncOperation()`/`updateSyncLog()` silently SKIPPED logging when the table was missing — syncs ran for months with zero trace (this is why the duplicate-race incident couldn't be attributed to a specific run). Both functions now call `ensureSyncLogsTable()` (CREATE TABLE IF NOT EXISTS, same pattern as `bokun_webhook_logs`), so every sync — including FAILED ones — gets a row with type/status/counts/`triggered_by`/duration.

### HOST NOTE — no PHP logs
**`log_errors` is `Off` server-wide**, so `error_log()` output is **NOT persisted anywhere** on this host. Debug via **DB side-effects** instead: `tours.last_sync` (bumped by a sync), **`sync_logs`** (one row per sync run incl. failures), `bokun_webhook_logs` (`processed` flag + captured `payload`), and the webhook's JSON response (`synced_dates`) — not PHP logs.
