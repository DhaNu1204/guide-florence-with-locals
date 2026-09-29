# Assistant go-live checklist (production)

Prepared 2026-09-29 in step 7.6. Production has all the assistant code (7.1–7.6, deploy `8f1b06d`) and the
assistant is **OFF**: `POST /api/assistant.php` answers `503 assistant_disabled`, status says `enabled:false`,
so admins see no Assistant button. Nothing below has been done yet.

## 1. The owner (before)
1. console.anthropic.com → API keys → create a second key named **`withlocals-production`**
   (the staging key stays for staging). Check the organisation's **monthly spend limit** is set.
2. SSH to the server and open the production settings file:
   `nano /home/u803853690/env/withlocals/.env`
3. Add at the end, on its own line (paste the real key, no quotes, no spaces):
   ```
   ANTHROPIC_API_KEY=sk-ant-...
   ```
   Save (Ctrl+O, Enter) and exit (Ctrl+X).
4. Tell Claude Code: **"key added, go live"**.

## 2. Claude Code (on "key added, go live")
Appends these lines to `/home/u803853690/env/withlocals/.env` (never prints the key):
```
# step 7.6 go-live: AI assistant on production
ASSISTANT_ENABLED=true
ASSISTANT_DAILY_TOKEN_CAP=300000
```
- `ANTHROPIC_API_KEY` — the owner adds it (step 1.3); Claude Code only checks it is present.
- `ASSISTANT_DAILY_TOKEN_CAP=300000` — the code's default, written explicitly so it is visible. It counts
  uncached input + output + cache writes + cache reads ÷ 10, from midnight Europe/Rome. Staging usage on
  29 Sep: ~1,600 cap tokens per question for normal questions (sudesh: 10 questions = 15,669), ~3,500 when
  questions chain several tools, so 300,000 ≈ 85–190 questions a day for both admins together. When it is
  reached the chat says "Today's assistant limit is reached — it resets at midnight" and the report counts
  a cap hit.
- **Later, only after the owner confirms Twilio shows `guide_tour_assigned_it` as APPROVED:**
  ```
  ASSIGN_WHATSAPP_TEMPLATE_SID=HX101215616db23747792e942de3aa259b
  ```
  Until then the assignment card simply has no "Send WhatsApp" box (the 21:30 digest still tells the guide).

Then it confirms with observed values: `assistant.php?action=status` → `enabled:true`; the Assistant button shows for dhanu and sudesh (admins), not for a viewer.

## 3. WhatsApp on production is REAL (checked 2026-09-29)
- `/home/u803853690/env/withlocals/.env` has **no `TWILIO_DRY_RUN` line** → it defaults to **off** → messages
  really go out. (`DIGEST_LIVE=true` is set there, and the 21:30 digest has been sending for real since step 3.10.)
- So once the template SID is in, a ticked "Send WhatsApp to <guide> now" box on a confirm card **sends a real
  WhatsApp** to that guide. It is only offered for today's tours, or tomorrow's after 21:30, and only when the guide
  has a valid number. Nothing is ever sent without a Confirm tap.

## 4. The 3 test questions (read-only; Claude Code asks them, 2 s apart, as dhanu)
| # | Question | Must match |
|---|---|---|
| 1 | "How many tours do we have tomorrow, and how many guests?" | Tours page → Tomorrow: departures + guests in the day header |
| 2 | "What's unassigned in the next 7 days?" | Unassigned Report for the same 7 days (same query) |
| 3 | "How much did we make yesterday?" | Daily P&L for yesterday: Net Revenue, Total Costs, Profit to the cent (owner only) |

Nothing is changed by these. The first real assignment is made by the owner from a card (Confirm), after which
`tools/assistant_usage.php` should show 1 confirm.

## 5. Switch it off instantly
In `/home/u803853690/env/withlocals/.env` change the line to
```
ASSISTANT_ENABLED=false
```
(or delete it — missing means off). It takes effect on the very next request: the settings file is read on every
request, there is no restart or cache. Every call then gets `503 assistant_disabled` and the button disappears on
the next page load. Bookings, Tours, payments, the digest — nothing else depends on it. Claude Code can do this on
request in seconds.

## 6. Watching (first week)
`FWL_API_DIR=~/domains/deetech.cc/public_html/withlocals/api /opt/alt/php82/usr/bin/php ~/fwl-tools/assistant_usage.php --days=7`
→ per day: questions per user, tokens and % of the cap, errors, cap hits, confirms, undos, WhatsApps sent
(dry run / not sent / failed counted apart).

## 7. Voice input
Mic button + IT/EN switch in the chat input (Chrome, Edge, Safari incl. iPhone; hidden where the browser has no
speech recognition, e.g. Firefox). The site now allows the microphone for itself (`Permissions-Policy:
microphone=(self)`). The first tap asks for microphone permission; the text lands in the box and is never sent by
itself. Could not be tried with a real voice from the test browser (it blocks microphones) — the permission-denied
line was seen there; **please try one spoken question on your phone** after go-live.
