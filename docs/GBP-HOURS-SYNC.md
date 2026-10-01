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

## 4. Current state — quota SOLVED, listing access is the open blocker

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

### The remaining blocker: no credentials can see the listing

Quota was never the only door. It was just the only one visible while every call
died at 429. The first successful `GET /v1/accounts` returned:

```
accounts/104353862439095562681
  "Michael Tressler (flinchbot)"   type: PERSONAL   role: None   UNVERIFIED
```

An empty personal container — no GASF location in it. And Business Profile Manager
under the **club** account reports **0 businesses / "You haven't added any
businesses"**.

So **neither account manages the GASF Business Profile listing.** A rebuilt refresh
token under either one would authenticate perfectly and still see nothing to write
hours to. Finding who holds the listing is now the critical path, and it is an
organisational question rather than a technical one — a club officer, a former
webmaster, or whoever originally claimed the profile.

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

## 6. Next steps

**Blocked on step 1 — everything after it is mechanical.**

1. **Find who manages the GASF Business Profile listing.** Neither the club account
   nor `flinchbot` does (§4). Check the profile's *Managers* list, or ask whoever
   claimed it originally.
2. **Have them add the club Google account as an Owner of the listing.** Owner, not
   Manager, and the club account rather than anyone's personal Gmail — this is a
   club asset that has to outlive whoever currently holds it.
3. **Create the OAuth consent screen + Desktop client inside
   `gas-calendar-sync-500618`** and consent as the club account. Everything then
   lives in one club-owned project: no `x-goog-user-project` header, no
   cross-project IAM grant, and `flinchbot`'s Service Usage Consumer role can be
   revoked as cleanup. Set the consent screen **External + In production** or the
   refresh token dies after 7 days (§3).
4. Replace `/home4/germanta/gasf-gbp-key.json` with the new three values and verify
   `GET /v1/accounts` returns the GASF location rather than an empty personal
   account.
5. Build `includes/class-google-business-profile.php` — mirror `Google_Calendar`
   structure, `refresh_token` grant, transient-cached access token.
6. Store the location ID once: `GET .../v1/accounts` then
   `GET .../v1/accounts/{id}/locations?readMask=name,title` → save
   `locations/{locationId}` in plugin settings; it never changes.
7. Implement the rolling-window rebuild of `specialHours` (§2 — full replace, not
   append).

If step 2 proves impossible, the fallback is to mint the refresh token from
whichever account *does* hold the listing, keeping the header + IAM grant from §4.
That works, but ties a club integration to one person's Google account.

## 7. Unrelated bug spotted — FIXED

`gasf-events.php` declared `Version: 0.13.0` while `GASF_EVENTS_VERSION` was
hard-coded `'0.12.0'` below it, so anything keyed off the constant was a version
behind. The constant is now derived from the header via `get_file_data()`, so the
two cannot drift again. Only the header needs bumping on a release.
