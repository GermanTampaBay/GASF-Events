# Google Business Profile — event-driven hours sync

Status doc for the integration that sets the GASF listing's **special hours** from
`gasf_event` calendar entries. Written 2026-07-19.

---

## 1. Goal

Our hall's opening hours vary with the event schedule (evening concerts, Friday
dinners, seasonal festivals). Today those are updated by hand in the Business
Profile UI and routinely go stale, so visitors arrive on wrong information.

Target: a scheduled sync that rebuilds the listing's `specialHours` from the
`gasf_event` CPT.

**Status: BUILT AND LIVE (v0.29.0, 2026-10-01).** First hours published. Automatic sync is still switched OFF on purpose — see §6.

## 2. Which API (this is the part that trips people up)

**Not the Places API.** Places is read-only — it can report `opening_hours` but
there is no write path, with any key or scope.

Hours are written through the **Google Business Profile API**:

| Purpose | Service |
|---|---|
| Resolve account + location IDs | `mybusinessaccountmanagement.googleapis.com` |
| Read/write hours | `mybusinessbusinessinformation.googleapis.com` |

```
PATCH https://mybusinessbusinessinformation.googleapis.com/v1/locations/{locationId}
      ?updateMask=specialHours
```

Use `specialHours` (dated overrides that expire on their own), **not**
`regularHours`. Overwriting `regularHours` per event means tracking and
restoring a baseline, which will drift.

### ⚠️ `specialHours` is a full replace, not an append

Every PATCH overwrites the entire set of special-hour periods. The sync cannot
push "the next event" incrementally — each run must read the calendar, compute
the **complete** desired list for a rolling window (~90 days), and write all of
it in one call.

Build it as "rebuild the whole window from the calendar." That is also
self-healing: a failed run is corrected by the next one. Get this wrong and each
sync silently wipes the previous events' hours.

## 3. Auth — service accounts do NOT work here

`includes/class-google-calendar.php` is the right structural model (transient-cached
token, `wp_remote_post` to the token endpoint, `WP_Error` handling) but its
**auth model does not carry over**. It uses a service account (RS256 JWT). GBP
locations are owned by a human Google account and cannot be shared with a
service account.

| | Calendar (existing) | Business Profile (new) |
|---|---|---|
| Identity | Service account | Google account managing the listing |
| Grant | `jwt-bearer` | `refresh_token` |
| Credentials | Key JSON | client_id + client_secret + refresh token |

Scope: `https://www.googleapis.com/auth/business.manage`

### Consent screen must stay "In production"

External + **Testing** status makes Google revoke refresh tokens after exactly
**7 days**, which would break the sync weekly. It is currently External +
In production. Do not click "Back to testing."

Unverified-app warning during consent is expected (sensitive scope, verification
skipped) — Advanced → "Go to … (unsafe)". Fine at one-user scale; 100-login cap.

## 4. Current state — SOLVED, ready to build

**Resolved 2026-10-01.** The quota wall is gone, and it was never a provisioning
failure. Google had allowlisted a **different project** the whole time.

### The allowlisted project is `gas-calendar-sync-500618` (number `572555189848`)

Support's reply pointed at a project "already active and associated with your
business". That number resolves to **GAS Calendar Sync** — the club's own existing
project, the one already running the ICS → Google Calendar feeds (6,435 Google
Calendar API requests on it). It is owned by the **club Google account** (the same
address configured as the Google Calendar destination in Events → Feeds), which is
why it was invisible from the personal `flinchbot` account that built all the
credentials. Both sides were right about different projects.

| | `gasf-places` (`656232842481`) | `gas-calendar-sync-500618` (`572555189848`) |
|---|---|---|
| Owner | personal `flinchbot` account | club account |
| Both GBP APIs enabled | ✅ (2026-07) | ✅ (2026-10-01) |
| **Requests per minute** | **0** | **300** |
| Create Location / day | — | 100 |
| SearchGoogleLocation / day | — | 200 |
| Update Location / day | — | 10,000 |

**The APIs must be ENABLED on the allowlisted project before any quota is visible.**
Until then the console redirects the quota URL to the API Library page with an
*Enable* button and no Quotas tab at all — which reads exactly like "this project
has no quota either". Enable first, then read.

### What made the first successful call work

Two things, neither of which required rebuilding credentials:

1. **`x-goog-user-project: 572555189848`** on the request. This bills the call to
   the allowlisted project. The project is otherwise baked into the credential —
   the OAuth client_id literally *begins with* its project number, so no config
   change can redirect an existing key file. The header is the only way to run one
   project's credentials against another project's quota.
2. **`roles/serviceusage.serviceUsageConsumer`** granted to the `flinchbot` account
   on `gas-calendar-sync-500618` (2026-10-01, by the club account as Owner).
   Without it the header returns `403 PERMISSION_DENIED` instead of `429` — and
   that 403 is itself the useful signal: it proves the redirect reached the target
   project.

With both in place the same untouched refresh token returned **HTTP 200** — the
first successful Business Profile API call, 79 days after the approval email.

### Listing access — it was never broken (correction, 2026-10-01)

**The existing credentials can read the listing. Nothing needs rebuilding.**

```
ACCOUNT accounts/104353862439095562681 | Michael Tressler (flinchbot) | PERSONAL
  locations: 1
   - locations/16878027369244959781
     title : German-American Society
     site  : http://www.germantampabay.com/
     addr  : 8098 66th Street North, Pinellas Park, Florida
```

**Location ID: `locations/16878027369244959781`** — this is the value §6 needs
stored in plugin settings. It never changes.

⚠️ **The trap that produced an hour of wrong conclusions.** `GET /v1/accounts`
lists **account containers, not locations**. The first successful call returned one
container — `type: PERSONAL`, `role: None`, `UNVERIFIED` — which looks exactly like
an empty account with no business in it. It is not. A personal Google account that
directly owns a listing renders precisely that way, and the location only appears
from:

```
GET /v1/accounts/{accountId}/locations?readMask=name,title,storefrontAddress
```

Reading the accounts list as proof of listing access is wrong. Always run the
locations query before concluding anything about who can see what. Business Profile
Manager showing "0 businesses" for a *different* account proves nothing about this
one either.

### History (resolved — kept because the dead ends cost 79 days)

- Approval email **2026-07-14**; quota on `gasf-places` never moved off 0.
- Escalated as case **`6-9389000041422`**; re-tested 2026-08-29 and 2026-08-31,
  both services still `quota_limit_value: 0`.
- Reapplied **2026-09-21** via the Basic API Access form → case
  **`4-6801000042156`**, answering "Cloud Project ID allowlisted?" = **No**,
  because the self-service quota-increase form dead-ends by requiring quota ≠ 0.
- That reapplication produced the 2026-10-01 reply naming `572555189848`.
- The "Account Management lags behind Business Information" folk wisdom was never
  what was happening: on `gasf-places` both sat at 0 permanently, and on the
  allowlisted project both read 300 immediately.
- Throughout, the refresh token kept exchanging for an access token, which also
  confirms the consent screen stayed External + In production (a lapse to Testing
  revokes the token after 7 days).

Interim, unchanged: special hours can be set by hand in the Business Profile UI.
The API is an efficiency win, not the only route to correct hours.

## 5. Server facts

- Bluehost shared hosting (cPanel), user `germanta`, **SSH port 2222**
- **SSH does NOT go to `germantampabay.com`.** The public domain sits behind
  Cloudflare, which does not proxy SSH, so ports 22 and 2222 are both closed at that
  name. SSH goes to the Bluehost box hostname / origin IP instead — read it from
  cPanel → *Server Information*, or the Bluehost panel. Deliberately not recorded
  here: this repo is public, and the origin address is what Cloudflare exists to
  keep off the open internet. Key: `gasf_bluehost` (ed25519), local, outside the repo.
- WP root: `/home4/germanta/public_html` → `germantampabay.com`
- Plugin: `/home4/germanta/public_html/wp-content/plugins/gasf-events`
- `dirname( ABSPATH )` = `/home4/germanta` — above docroot, not web-reachable
- Existing service-account key for Calendar: `/home4/germanta/gasf-calsync-key.json` (600)
- Three other WP installs on the account (`/foundation`, `/dancers`, `/krampus`) — unrelated

Never place credentials under `public_html`; it is publicly served.

## 6. Built and live (v0.29.0, 2026-10-01)

### What shipped

- **`includes/class-google-business-profile.php`** — refresh_token grant,
  transient-cached token, bounded retry. Sends `x-goog-user-project` on every
  call. Does NOT retry 401/403/404: those are configuration, and retrying them
  burns the 300/min quota three times faster.
- **`includes/class-hours-sync.php`** — builds `specialHours` from the calendar,
  change-triggered with a 120s debounce plus a daily sweep, payload hashed
  against the last push.

### The model, as built

`regularHours` is the always-true baseline and **the plugin never writes it**.
`specialHours` is generated per-date from `gasf_event`.

A day's published hours are the **union** of its regular hours and its events, not
a replacement — Saturday 18–22 plus an Oktoberfest event 12–22 is 12–22; a
Saturday with a 19:00–23:00 concert is 18–23. A day whose union *equals* its
regular hours emits nothing, so the published set only ever holds real exceptions.

**A special-hours date replaces that whole day.** If a day needs any override it
must describe the entire day, not just the extra part — which is why Sat 2026-10-17
publishes two periods (12:30–14:00 and 18:00–22:00) even though the evening one is
identical to regular hours. Emitting only the afternoon would have deleted the
evening.

### Safety properties worth not breaking

- Full replace every run, so it is self-healing and carries no drift state.
- An empty event query does **not** publish an empty set — indistinguishable from
  "the calendar failed to load", and one PATCH would wipe every override. Same
  fail-safe as `Feeds::run()` refusing to prune on an empty fetch.
- All-day events are skipped, not guessed at. Events spanning ≥24h are skipped —
  Google cannot express them. Cancelled events do not open the hall. All three are
  reported in the run's `notes` rather than silently dropped.

### The 2026-10-01 cutover

Published in **one** PATCH (`updateMask=regularHours,specialHours`) so there was
never a moment where an Oktoberfest Saturday showed 18:00 instead of noon:

```
regularHours  SATURDAY 18:00-22:00      (replacing FRI 17-22 + SAT 12-22)
specialHours  9 periods, 2026-10-02 .. 2026-10-17
```

Both old `regularHours` values were hand-copies of Oktoberfest event times and
would have needed hand-reverting on Oct 11. They now live as special hours that
expire on their own.

The window was deliberately bounded to 17 days for the first write, so a bug could
not reach beyond two weeks. `build()` takes a regular-hours override for exactly
this: the intended baseline has to drive the computation **before** it is
published, or the union is taken against the values being replaced.

⚠️ **Google echoes `endDate` back on every period**, set equal to `startDate` when
you omit it (its documented default). A readback that appears to show every period
ending "the next day" is almost certainly a display bug in whatever is printing it —
compare the dates before believing it.

### Current state and what is left

| | |
|---|---|
| Hours published | ✅ 2026-10-02 .. 2026-10-17 |
| `gasf_events_gbp_enable` | **false** — no cron, no trigger fires |
| `gasf_events_gbp_window` | 17 days (raise to 90 when trusted) |
| `gasf_events_gbp_location` | `locations/16878027369244959781` |

1. **Watch the Oct 2–3 Oktoberfest weekend land correctly in the wild**, then
   enable auto-sync and widen the window to 90.
2. Data gaps found during the cutover, all fixable without code: *Schuhplattler
   Practice* 2026-10-06 is a **draft** (correctly excluded); *Krampus Verein
   Monthly Meetup* 2026-10-16 has start == end so no hours can be derived;
   *Crafting at the Club* has no occurrence dated today or later.
3. Admin UI. The settings are options only — there is no Events → Settings panel
   for enable / window / buffer / location yet.

### Optional cleanup, not required

The credentials live in the personal `gasf-places` project and reach quota through
a header plus a cross-project IAM grant. That works and is verified. Moving the
OAuth consent screen + Desktop client into `gas-calendar-sync-500618` and
re-consenting as the club account would drop both the header and the grant, and put
the integration wholly under club ownership. Worth doing eventually for an asset
that should outlive any one person's Google account — but it buys durability, not
function, and it means a fresh consent flow (External + **In production**, §3).

The club account was added as an Owner of the Business Profile listing on
2026-10-01. That was done on a mistaken reading (see the correction in §4) but is
worth keeping regardless: listing ownership should not rest on one personal Gmail.

## 7. Unrelated bug spotted — FIXED

`gasf-events.php` declared `Version: 0.13.0` while `GASF_EVENTS_VERSION` was
hard-coded `'0.12.0'` below it, so anything keyed off the constant was a version
behind. The constant is now derived from the header via `get_file_data()`, so the
two cannot drift again. Only the header needs bumping on a release.
