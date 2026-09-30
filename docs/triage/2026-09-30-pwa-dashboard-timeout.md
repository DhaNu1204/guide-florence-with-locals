# Triage 2026-09-30 — installed app (PWA) shows "Could not load the dashboard / tours list"

**Symptom.** On the owner's iPhone, on mobile data, the home-screen app shows "Could not load the
dashboard. The server did not answer in time — the connection is probably weak" on Dashboard and
Tours. `/api/health.php` and the app in a normal / private Safari tab load on the same connection;
the home-screen app works on WiFi. Reported attempts: 10:15 and 18:40–18:45 Rome.

## Evidence

### 1. What production runs, and what changed
- `GET /api/health.php` → `sha 8f1b06d` (step 7.6 merge). Live `index.html` + `assets/index-R8cDs97j.js`
  written 2026-09-29 20:08 UTC. Staging `4e78fd8`.
- There is **no "dashboard mobile" change** in git or on the server: `src/components/Dashboard.jsx`
  last changed in step 4.8 (`2faddb5`, 2026-09-23). The message comes from
  `src/services/netPolicy.js:116` — `git blame` → `2faddb5` (step 4.8).
- Since 2026-09-23 nothing that decides how these screens load has changed: `sw.js` (last 4.8),
  `netPolicy.js` (15 s per read + 1 automatic retry, 10 s verify; 4.8/34b754e), `authFetch.js`,
  `LoadProblem.jsx`. **Every bundle that can show this message makes the same requests with the
  same timeouts.** An old bundle cannot be the cause (a pre-4.8 bundle has no such message).

### 2. Service worker (`public/sw.js`, cache `fwl-v2`, unchanged since 2026-09-23)
- `/api/` is never intercepted (`url.pathname.startsWith('/api/')` → `return`), so the SW cannot
  serve stale API data or delay an API request.
- Navigations: network first; after 5 s without an answer the cached `index.html` is served, marked
  `<meta name="fwl-shell">`. Hashed `/assets/*`: stale-while-revalidate.
- Update path today: `navigator.serviceWorker.register('/sw.js')` on load only. There is **no
  update check at all**. An iOS home-screen app that is resumed (not cold-started) keeps running
  the JS it started with, so it can run an old bundle for days after a deploy. A load that falls
  back to the cached shell also runs the old bundle. Not the cause here (see 1), but a real gap:
  requirement (a).

### 3. Saved data on the phone
- No IndexedDB. localStorage holds `token`, `userRole`, `tours_v1` + a duplicate legacy `tours`
  (~0.5 MB each, rewritten on every list load; `mysqlDB.js:396-398`), ticket caches, `fwl:last-build`.
- Nothing saved is read before the requests start except the token. The token was valid in every
  failing row (the auth check answered `ok` in 150–280 ms), and an expired token gives a fast 401,
  not a hang. **Ruled out.**

### 4. Field recorder (`client_perf`, production; `created_at` is UTC) and server logs
iPhone rows, Rome time (`vms` = auth check ms, `lms` = page data ms, `t` = timers fired, `sh` = SW
served the cached shell):

| id | Rome | user | route | verify | list | t | sh |
|---|---|---|---|---|---|---|---|
| 66 | 09-27 10:14:56 | 1 | / | ok 160 ms | **failed 30 012 ms** | 8 | 0 |
| 67 | 09-27 10:15:33 | 1 | / | ok 153 ms | **failed 30 014 ms** | 2 | 0 |
| 85 | 09-29 11:02:18 | 1 | / | ok 267 ms | **failed 30 016 ms** | 8 | 0 |
| 90 | 09-29 16:41:29 | 1 | / | ok 241 ms | pending (left) | 1 | 0 |
| 97 | 09-30 09:52:37 | 1 | / | ok 279 ms | **failed 26 141 ms** | 2 | 0 |
| 98 | 09-30 09:53:09 | 1 | /tours | **timeout 10 004 ms** | – | 2 | **1** |
| 99 | 09-30 09:53:29 | 1 | /tours | **timeout 10 002 ms** | – | 1 | **1** |
| 106 | 09-30 16:50:14 | 5 | /priority-tickets | ok 147 ms | **failed 20 380 ms** | 0 | 0 |
| 107 | 09-30 16:50:28 | 5 | /tours | **timeout 10 003 ms** | pending | 1 | **1** |
| 108 | 09-30 18:47:55 | 1 | / | ok 165 ms | ok 1 027 ms | 0 | 0 |

- **The failures started 2026-09-27**, before the 2026-09-29 deploy, and user 5's iPhone has them too.
- Pattern: the ~70-byte auth check answers fast, the list requests (60–80 KB compressed each) get
  no answer until the timers fire; in 98/99/107 even the 1 KB `index.html` and the auth check got
  nothing for 5–10 s. That is a **stalled connection**, not a slow one (a slow link still
  delivers 60 KB in far less than 15 s).
- **The reported attempts at 10:15 and 18:40–18:45 left no row at all.** Row 108 (18:47:55, auth
  check started 19.6 s after entry = a fresh login) is the private-tab test that worked. So from
  the home-screen app nothing reached the server at those times — not even the recorder's own
  beacon, which is sent at the end of every load.
- Server side at the same minutes: `~/logs/api-error.log` shows normal cron/webhook syncs
  (10:15:01–10:15:10, 18:30–18:42), no PHP errors; other users loaded normally all day
  (rows 100–105, 1–1.3 s). Measured on staging via the same edge: every request 0.5–0.9 s.
- The hosting edge advertises HTTP/3: `alt-svc: h3=":443"; ma=86400` (`Server: hcdn`); the
  domain has AAAA and A records. The home-screen app keeps its own network state separate from
  Safari's, so it can keep a connection (or HTTP/3 path) that stalls on mobile data while a Safari
  tab uses a fresh one. **Not provable with today's data** — see "What cannot be proven yet".
- **The recorder cannot tell the home-screen app from a Safari tab**: it records `sw_controlled`
  (both are 1) and a coarse device label (`iOS/Safari 27`), no display mode, no per-install id,
  no build, and a load whose beacon cannot get out is lost for good.

### 5. What the Dashboard asks for (staging, same code, through the same edge)
Serial, one after another, each 15 s + one retry:
`tours.php?upcoming&per_page=500&view=list` 61.6 KB br (475 KB JSON) → `tours.php?per_page=500&view=list`
81.6 KB br (567 KB) (normally served from the 1-min `tours_v1` cache — which then holds the
*upcoming* list, known poisoning, step 5.1) → guides 2 KB → guide-requests recent → 
`guide-payments.php?action=pending_tours` **68.6 KB br (510 KB JSON) just to read a count**.
≈ 215 KB compressed / 1.55 MB JSON before anything is shown. Tours asks for ≈ 87 KB in parallel.
The Dashboard is the heaviest screen and the only serial one: on a stalling link the first request
alone costs 30 s before the error.

## Root cause
1. **Proven:** during the reported attempts the home-screen app's requests never reached the
   server (no recorder row, no server error, server fast for everyone else). The stall is on the
   path between the installed app and the Hostinger edge, on mobile data only. It is **not** an
   old bundle, the service worker, saved data or the server.
2. **Proven (app side, makes it worse):** the Dashboard chains ~215 KB of requests; a failure
   shows an empty page with a guessed reason ("probably weak"); nothing saved is shown; the
   recorder cannot identify the installed app or report a load after the fact; and an installed
   app never checks for a new version.
3. **Not yet proven:** which transport layer stalls (HTTP/3/QUIC vs TCP, IPv6 vs IPv4, a stuck
   pooled connection). The fix below adds a measured reachability probe and a delayed-report
   outbox so the next failure says which.

## Dashboard side questions
- Times with seconds: `tour.time` is `HH:MM:SS` from MySQL and is printed raw
  (`Dashboard.jsx` needs-guide list and both tour lists). Cosmetic bug.
- "Tours needing a guide — next 7 days (31)" vs Unassigned Report / assistant (20): the Dashboard
  counts **bookings** (a group of 3 counts 3), treats "ticket" by title keywords
  (`tourFilters.js`), and uses each booking's own guide. The report and the assistant count
  **departures** (one per group), use `products.product_type`, and the group's effective guide
  (`fwlUnassignedReport`, step 3.5). Production 2026-09-30 18:55 Rome for 30 Sep–7 Oct:
  report = 24 departures; Dashboard-style replica = 56 bookings in 44 departures, 25 of them
  ticket products by `product_type` (replica approximate: no title-override list). **A bug**
  (the heading says tours, the owner acts on it, and Phase 3 made the report the single rule).

## Fix → step 4.10 (new, Phase 4)
See `docs/IMPLEMENTATION_PLAN.md` 4.10.

## Update 2026-09-30 evening — root cause found (hosting setting)
**Root cause:** the Hostinger edge **Security level** for `withlocals.deetech.cc` was **Medium**. At
that level the edge challenges visitors it considers risky - here the mobile carrier's (shared,
carrier-NAT) IP addresses. A browser tab can pass a challenge page; the installed app's
background API calls (JSON `fetch`) cannot, so they got no usable answer until the app's timers
fired. That explains every observation: only on mobile data, WiFi fine; the server and PHP never
saw the requests (no recorder row, no log line); the other admin's iPhone hit it too.
The exact behaviour of the edge towards a challenged `fetch` (held open vs. answered with a
challenge page) was not captured - the owner's fix removed it before it could be recorded.

**Fix (owner, hPanel, ~20:50 Rome):** Security level → **Essentially off**. No code change.
Rule recorded in `CLAUDE.md` and both deploy skills: Hostinger edge Security level for withlocals must stay 'Essentially off'. Medium challenges mobile-carrier IPs and breaks the installed app's API calls.

**Checks after the change (production, recorder rows, Rome time):**
- 20:52:29, 20:52:48, 20:53:50, 20:55:18 - home-screen app (`display_mode=standalone`, new install
  `96119a8a…`, build `CHHFPWak`): auth check 141–207 ms, Dashboard data 324 ms – 4.2 s, all OK.
- 20:53:27 - one more failed load, 3 minutes after the change: 13 timers, every Dashboard request
  stalled. Most likely the setting still reaching all edge servers; to be confirmed by the next
  day's rows (no failed loads expected).
- Same automated Chromium, 20:58: production answered the page directly (200); **staging still
  served the challenge first (403 "Loading …")** - staging is still above "Essentially off".
- Production smoke test 12/12.

**Recorder fixes found on that row (step 4.10a):** `stuck` lost its commas on the server
(`perfStr` strips them - now stored space-separated), and the row went out before the server
check answered (a running check now holds the row, at most 6 s).

