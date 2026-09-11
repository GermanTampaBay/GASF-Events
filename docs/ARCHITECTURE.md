# GASF Events — Native MEC Replacement: Architecture Spec

**Status:** Draft for review (no code yet)
**Author:** Claude (investigation + spec), for flinchbot
**Date:** 2026-06-27
**Target site:** germantampabay.com (Bluehost, WordPress, theme `hoot-du-premium`, table prefix `_4UX_`)
**Decisions locked by user:** Clean `gasf_event` data model · Native FB importer (drop 3rd-party) · Spec-first

---

## 1. Goal & Rationale

Replace **Modern Events Calendar (MEC) Lite 7.33.0** — plus the third-party `mec-advanced-importer` and the pile of MU "band-aid" modules that exist to patch it — with a single **lean, native WordPress events plugin ("GASF Events")** that we fully own.

**Why now:** MEC + the importer are an update-fragility liability. The MU hacks (Modules A–F, H) exist almost entirely to *fix bugs in the third-party importer*. Every MEC plugin or importer update risks breaking the whole calendar, the hourly Facebook sync, the hallway signage, and (soon) the kiosk. We use a tiny fraction of MEC's surface, so owning a purpose-built replacement is both simpler and more robust than maintaining the patch stack.

**Guiding principle:** Build for *exactly what the club uses* — verified by querying the live 860-event dataset — not for MEC's feature catalog.

### 1.1 Hard constraint — ZERO production interference (this is built in the background)
The live site must keep running, byte-for-byte unchanged, until a single deliberate cutover at the very end. The whole build is **additive and isolated**:
- **Own namespace only.** `gasf_event` post type, temporary `/gasf-events/` rewrite slug, `_gasf_*` meta, `gasf_events_*` options. The plugin **never reads or writes** `mec-events`, `mec_*` meta, or the `_4UX_mec_*` tables — **except read-only** during migration (§8).
- **No collisions / no overrides.** It registers nothing that touches MEC or the MU modules: no `[MEC]`/`[mec_*]` shortcodes, no `single-mec-events.php` template hook, no filters on MEC output. Its `template_include` is guarded to `is_singular('gasf_event')` only; its menu/CPT are separate.
- **Aliases deferred to cutover.** The `[mec_*]→[gasf_*]` back-compat aliases (§5.3) are **NOT** registered during the parallel run — the live MU modules still define those tags and a second declaration would fatal. Parallel run exposes `[gasf_events]` only.
- **Every outward feature ships OFF.** The native FB importer (§6), Eventbrite publishing (§7.1), and Google sync stay **gated off by default** until cutover. The existing MEC importer + `gasf-event-calendars` keep doing the real work in the meantime.
- **No edits to existing files** — not the MU plugins, not `gasf-event-calendars`, not the theme, not the SiteOrigin CSS. 100% self-contained in `wp-content/plugins/gasf-events/`.
- **Cutover (§8.1 step 4 / §9) is the only production-touching step** — deliberate, gated, and reversible.

---

## 2. What the Club Actually Uses (evidence-based scope)

Queried against all 860 published events on 2026-06-27:

| Capability | Verdict | Evidence |
|---|---|---|
| Single-date events (start/end date + time) | **KEEP** | Universal across all events |
| All-day flag, hide-time / hide-end-time | **KEEP** | Present on all events |
| Featured image (FB cover) | **KEEP** | 854/860 events |
| Event status (cancelled / postponed / sold-out / online-only) | **KEEP** | On ~79 hand-made events |
| "More info" link/title, cost | **KEEP (minor)** | On the 79 manual events; cost only used for schema offers |
| Single venue (the club) | **KEEP as a setting** | 285+554 events point at the club address; venue is cosmetic, not multi-location |
| Single organizer | **KEEP as a setting** | `mec_organizer` terms: "Eventbrite"=1, "Organizer Name"=0 — effectively unused |
| **Recurrence engine** | **DROP** | 876 events = no-repeat, exactly **1** uses repeat. Recurring series (e.g. Biergartens) already exist as **flat individual events**, one per occurrence |
| **Categories** | **DROP** | **Zero** events have a category assigned |
| **Booking / tickets / fees** | **DROP** | Meta exists on 79 events but MEC Lite can't book — dead data |
| Map view, masonry, slider, countdown, carousel, daily, weekly views | **DROP** | Saved as MEC configs but **placed on no page** |

**Display surface actually placed on the live site (the only views we must reproduce):**

| Page | Placement | View needed |
|---|---|---|
| 6058 "Monthly Calendar View" | `[MEC id="2262"]` | **Month grid** |
| 8647 "Calendar of Events" | `[MEC id="2261"]` | **Full calendar** (month grid + nav) |
| 12730 "Calendar-Signage" | `[MEC id="10763"]` | **Tile view (digital signage)** |
| 13415 | `[MEC id="2266"]` | **Upcoming list** |
| 16034 Euchre, 16065 Crafting | `[mec_upcoming_dates ...]` | Custom shortcode (ours, repoint) |

So GASF Events must ship **four front-end renderers**: month grid, upcoming list, signage tile, single-event page. Everything else MEC does, we delete.

---

## 3. Current Coupling Map (what the replacement must absorb)

**The encouraging finding:** every *read/display* component reads **raw `mec_*` postmeta only** — none call MEC's PHP classes (`MEC::`) or touch the `_4UX_mec_events` / `_4UX_mec_dates` tables. So the read side is trivially repointable. All the genuinely hard coupling is on the **write (import)** side.

### Write side — HIGH coupling (the hard part, being rewritten natively)
| Component | Role | Disposition |
|---|---|---|
| `mec-advanced-importer` (3rd-party plugin) | FB Graph API → creates `mec-events` posts | **DELETE** (replaced by native importer) |
| `gasf-mec-importer.php` Modules A–F | Band-aids: cron fix, forced FB defaults, time-window filter, recurrence-as-custom-days, dedup sweep, meta-bloat cap | **DELETE** (obsolete once importer is native) |
| `H-fb-refresh-existing.php` | FB-as-source-of-truth refresh + SHA1 cover dedup | **PORT logic** into native importer (the *generic* FB logic is reusable; the MEC-write logic is replaced) |

### Read side — LOW coupling (raw postmeta; repoint to `_gasf_*`)
| Component | Role | Disposition |
|---|---|---|
| `templates/single-mec-events.php` (Module G) | Branded single-event page | **PORT** to native single template, read `_gasf_*` |
| `05-yoast-mec-schema.php` | JSON-LD Event schema injector | **PORT** into plugin, build schema from `_gasf_*` |
| `16-feierabend-dates.php` → `[mec_upcoming_dates]` | Euchre/Crafting upcoming lists | **REPOINT** |
| `german-dinner-events.php` → `[german_dinner_events]` | Dinner Night list | **REPOINT** |
| `24-bayern-match-events.php` → `[bayern_match_events]` | Next 5 Bayern matches | **REPOINT** |
| `world-cup-schedule.php` → `[world_cup_schedule]` | World Cup watch parties | **REPOINT** |
| `22-calendar-print-button.php` | Print button (targets MEC's rendered CSS classes) | **REPLACE** with first-class print route + print stylesheet (§5.5) |

### Adjacent — minimal coupling
| Component | Role | Disposition |
|---|---|---|
| `26-calendar-sync.php` | Reads MEC **iCal feed URL** → pushes to Google Calendar (currently OFF) | **ABSORB** into core plugin as the Google-Calendar destination (§7.1); retire the MU module |
| `gasf-event-calendars` plugin | Publishes events to Eventbrite; coupling isolated to **one file** `includes/class-mec-reader.php` (reads `mec-events` + `mec_*` postmeta) | **ABSORB & RETIRE** — Eventbrite client → core plugin Bulk Action; `class-mec-reader.php` dropped (§4.5, §7.1) |

---

## 4. Data Model — `gasf_event`

### 4.1 Custom Post Type
- **Post type:** `gasf_event`
- **Rewrite slug:** `events` (matches MEC's current slug → **preserves all `/events/<slug>/` URLs**)
- **`has_archive`:** true
- **Supports:** title, editor (description), thumbnail (cover), revisions, author
- **Public, show_in_rest:** true (Gutenberg + REST feed)
- Migration converts existing posts **in place** (same IDs, slugs, dates, thumbnails) — see §8.

### 4.2 Meta schema (clean — replaces MEC's split keys)

| New meta key | Type | Notes / replaces |
|---|---|---|
| `_gasf_start` | string `Y-m-d H:i:s` (site-local) | Canonical start. Replaces `mec_start_date` + `mec_start_time_hour/minutes/ampm` |
| `_gasf_end` | string `Y-m-d H:i:s` (site-local) | Canonical end. Replaces `mec_end_date` + `mec_end_time_*` |
| `_gasf_start_ts` | int (UTC unix) | DST-correct sort/range key (via `wp_timezone()`). Replaces `mec_start_day_seconds` math |
| `_gasf_end_ts` | int (UTC unix) | Range key |
| `_gasf_all_day` | bool (`0/1`) | Replaces `mec_allday` |
| `_gasf_hide_time` | bool | Replaces `mec_hide_time` |
| `_gasf_hide_end` | bool | Replaces `mec_hide_end_time` |
| `_gasf_status` | enum: `''`,`cancelled`,`postponed`,`sold_out`,`online_only` | Replaces `mec_event_status` |
| `_gasf_status_reason` | string | Optional cancellation note |
| `_gasf_online_link` | url | For `online_only` |
| `_gasf_more_info_url` / `_gasf_more_info_title` | string | Replaces `mec_more_info*` |
| `_gasf_cost` | decimal | Only set when > 0; drives schema `offers` |
| `_gasf_source` | enum: `manual`,`facebook`,`eventbrite` | **Provenance.** Manual events have no `_gasf_fb_event_id`; the FB importer only ever touches `_gasf_source = facebook`. Drives the admin "source" badge |
| `_gasf_fb_event_id` | string | FB dedup key. Replaces `mec_advimp_facebook_event_id` |
| `_gasf_fb_account` | string | Which FB page sourced it (when >1 page) |
| `_gasf_fb_cover_id` | string | Last-synced FB cover id/hash (change detection) |
| `_gasf_fb_missing` | int (0–2) | Consecutive not-found counter → auto-draft |
| `_gasf_sync_locked` | bool | On an FB event, the admin "pin" — sync skips the whole event (see §4.6) |
| `_gasf_series_id` | string | Groups occurrences of a recurring event (manual **or** FB) for series-level edit/delete (see §4.7) |
| `_gasf_series_role` | enum: `single`,`master`,`occurrence` | Series membership; `master` holds the recurrence template |
| `_gasf_eventbrite_id` | string | Eventbrite event id once published (also the "already published" check) |
| `_gasf_eventbrite_url` | url | Public Eventbrite listing (shown in the All Events column) |
| `_gasf_eventbrite_status` | enum: `''`,`published`,`error` (+ `_gasf_eventbrite_synced_at`) | Last publish/update outcome |
| `_gasf_views` | int (+ `_gasf_views_daily` roll-up) | Lifetime page views; web/kiosk split (§4.5.1) |
| `_gasf_cover_sha1` | string (**on attachment**) | SHA1 of cover file for master-attachment dedup |

**Timezone rule:** all display/derivation uses `wp_timezone()` (site tz, currently America/New_York). `_gasf_*_ts` are the only UTC values; they exist purely for fast, DST-safe range queries and sorting. This eliminates MEC's awkward `day_seconds` scheme.

### 4.3 Venue, organizer & default image — settings, not taxonomies
Three global plugin options replace MEC's taxonomies and feed the schema (§5.7):
```
gasf_events_venue       = { name, street, city, state, zip, country, lat, lng, hide_map (bool) }
gasf_events_organizer   = { name, url }                  // → schema Event.organizer {name, url}
gasf_events_default_image = attachment_id | url          // site-wide fallback cover
gasf_events_type_rules  = [ { match, icon, color }, … ]  // calendar emoji/colour, first match wins
```
- **Venue default:** *German American Society, 8098 66th Street North, Pinellas Park, FL 33781*. Optional per-event override meta `_gasf_venue_override` (rare).
- **Organizer default:** *German American Society* + the club URL. Optional per-event override meta `_gasf_organizer_override`.
- **Default image (required for schema):** Google's Event rich results **require an `image`**, and some FB/manual events arrive with no cover. So a configurable fallback image is used everywhere a cover is missing — in the month grid, list, signage tiles, the single page, **and** the JSON-LD `image`. Guarantees no event is image-less.
- **Calendar icons & colours (v0.21.0):** each event chip carries an emoji and a soft background colour. `Event::builtin_type()` guesses both from a shipped keyword map over the title *and* description; `gasf_events_type_rules` lets a maintainer override that from Events → Settings with ordered "title contains" rules (first match wins), so naming an event's look no longer needs a code change. Rules match the **title only** — an explicit rule shouldn't fire on stray description text. A blank emoji or colour in a rule inherits that field from the built-in guess, so one field can be overridden alone.
- **Drop** `mec_location`/`mec_organizer` taxonomies entirely.

### 4.4 Tables dropped after cutover
`_4UX_mec_events`, `_4UX_mec_dates` — the native model needs neither (no recurrence/occurrence expansion table; start/end live on the post). Removed in the decommission phase.

### 4.5 Admin UI — manual add / edit / delete (maintainer-friendly)
Because `gasf_event` is a normal CPT, WordPress gives **Add New / Edit / Trash / Delete** for free in the standard list table. On top of that we ship a single clean **"Event Details" meta box** — designed for non-technical maintainers (the CLAUDE.md admin-simplicity constraint), not MEC's wall of tabs:
- **Title** + **description** (standard WP editor).
- **Start / End** — native date + time pickers (one row each; an "All day" checkbox hides the time fields). Writes `_gasf_start/_gasf_end` and recomputes `_gasf_*_ts` on save via `wp_timezone()`.
- **Featured image** — standard WP featured-image box = the event cover.
- **Status** — dropdown (`Scheduled / Cancelled / Postponed / Sold out / Online only`); shows a reason field + online link when relevant.
- **More-info link** (url + label), optional **cost**.
- **Venue** — defaults to the club; a collapsed "override venue" panel for the rare off-site event.
- **Source badge** (read-only) — "Manual" vs "Facebook", plus the **"Pin (don't let Facebook sync overwrite)"** toggle on FB events (§4.6).
- **Validation:** end ≥ start; warn on save if a manual event collides with an identical FB event. Capability `edit_gasf_events` maps to the Maintainer/Admin role split.

So: maintainers add an event with title + image + start/end + status and save — nothing else required. Editing and deleting are the standard WP flows.

#### "All Events" list view + Bulk Actions (MEC-style)
The standard CPT list table at `edit.php?post_type=gasf_event` is the **All Events** view, enhanced:
- **Columns:** cover thumbnail · title · **Added** (post date — when it entered the system, sortable) · **Event date** (`_gasf_start`, sortable; shows end too when multi-day) · **Recurring** (✓ when part of a series, §4.7) · Status · Source (Manual / Facebook) · **Views** (lifetime page views, §4.5.1) · **Eventbrite** (published badge + link, or "—").
- **Filters / search:**
  - **Text search** — finds an event by title (prominent search box).
  - **Date-range filter** — from–to on the **event date** (e.g. "show everything in Oct 2026").
  - Plus month, **Upcoming / Past**, Status, Source, Series, Recurring.
- **Bulk Actions** (multiselect rows → apply):
  | Action | Behavior |
  |---|---|
  | **Publish to Eventbrite** | Queues selected events to the in-core Eventbrite publisher (§7.1); writes `_gasf_eventbrite_*`. Already-published events **update** their listing (full three-way sync) |
  | **Move to Trash** | Native WP bulk trash (also unpublishes any linked Eventbrite listing — §7.1) |
  | **Export CSV** | Streams a `.csv` of the selected events (title, added, start, end, status, venue, url, source, views, Eventbrite URL) |
  | **Export ICS** | Streams a `.ics` (one `VEVENT` per selected event) — same generator as the feeds (§7) |
- **Per-row quick actions:** "Publish to Eventbrite", **"Stats"** (→ per-event view stats, §4.5.1), plus native Edit/Trash/View.

#### 4.5.1 Per-event view stats ("Stats" link)
A lightweight, self-contained page-view counter — no third-party analytics required:
- **Counting:** a tiny JS beacon on the single-event page calls a REST endpoint that increments `_gasf_views` (and a daily roll-up for trends). **Beacon, not server-side PHP increment**, because Cloudflare/SpeedyCache serve cached HTML — a server counter would miss cached hits. Bots and logged-in admins are excluded.
- **Kiosk-aware:** the same endpoint is hit when a visitor taps the event on the hallway kiosk (via the REST feed, §7), so kiosk interest counts too — labeled separately (web vs. kiosk) if useful.
- **Surfacing:** a **Views** column in the list + a **"Stats"** row action opening a small panel (lifetime views, last-30-days trend, web/kiosk split, plus quick links to the FB and Eventbrite copies). Gives the club a real sense of which events draw attention.

### 4.6 Manual vs. Facebook coexistence (so the importer never clobbers hand-made events)
The FB source-of-truth refresh (§6) is the one thing that could overwrite a maintainer's work, so the boundary is explicit:
- The importer **only ever reads/writes events where `_gasf_source = facebook`** (identified by `_gasf_fb_event_id`). Manual events are invisible to it — never updated, never auto-drafted.
- **Deletion semantics:** manual events delete via WP Trash like any post. FB events that vanish from Facebook auto-draft (not trash → avoids the old trash-limbo dedup bug); a maintainer can then permanently delete or keep them.
- **Auto-draft alert (v0.14.0):** every sync run that auto-drafts anything sends ONE summary email to the address in Events → Settings → "Alert email" (`gasf_events_alert_email`; blank = off), listing each event with its edit link and the republish + sync-lock recovery steps. Added after the 2026-07-22 incident where FB's Graph API silently dropped a single *valid* occurrence of a recurring event (while facebook.com still showed it), and the auto-draft rule hid a live event with no trace — API absence is not proof of source deletion, so a human gets told every time.

#### Pinning an FB event (the override) — whole-event for v1
Today Module H always wins: it rewrites title/description/date/time/cover on every hourly sync, so any maintainer correction snaps back within the hour. The pin lets the club override Facebook. **v1 = whole-event pin:**
- A single boolean `_gasf_sync_locked`. When set, the importer **skips the event entirely** — no field updates, **and** it's exempt from FB-disappeared auto-draft (once you own it, FB removing its copy shouldn't unpublish yours). Mental model: *"this event is now mine."* One field of state, trivial to reason about, fits the admin-simplicity constraint.
- **Auto-pin on edit (included):** when a maintainer edits any synced field of an FB event in wp-admin, auto-set `_gasf_sync_locked` and show a dismissible notice — *"This event is now manually managed; Facebook updates are paused. [Resume syncing]."* This makes the behavior discoverable instead of a hidden gotcha where edits silently revert. "Resume syncing" clears the pin and the next sync re-adopts FB's values.
- **Why not per-field in v1:** per-field is coarser to *not* do but finer to build. The good version isn't per-field checkboxes (UI clutter) — it's **automatic dirty-tracking**: snapshot each field's last-synced FB value (`_gasf_fb_snapshot`), and on sync only update fields still matching their snapshot, so *"whatever you edited stays edited, everything else keeps syncing."* That's the **v2 upgrade**; the data model is shaped so it can layer on later (add `_gasf_fb_snapshot` + treat `_gasf_sync_locked` as the "all fields" case) **without migration**.
- **Recommendation:** ship whole-event + auto-pin-on-edit; revisit per-field only if the club hits the "I just wanted to fix the title but lost the new cover" case in practice.

### 4.7 Recurring events as a lightweight **series** (big win — replaces hand-creating each occurrence)
The club currently hand-creates every Euchre night, Crafting session, Dinner Night, etc. one at a time. We make recurrence first-class **without** reintroducing MEC's recurrence engine — the trick: events stay **materialized as flat individual posts** (so the grid, single page, SEO, and feeds stay dead simple), but they're **grouped into a series** for management.

**Model (as built in P2):** a series shares a `_gasf_series_id`. The **first event doubles as the series anchor** (`_gasf_series_role = master`) — it is a real, displayed event that also carries the recurrence rule (`_gasf_repeat` / `_gasf_repeat_until` / `_gasf_repeat_count`); every later date is a flat `gasf_event` with `_gasf_series_role = occurrence`. Standalone events are `single`. **No hidden template post** (simpler than the original sketch; every member is a real, displayable event, which keeps the "flat-but-grouped" promise intact). FB recurring events (P5) reuse the same `_gasf_series_id` with all members as `occurrence` (their template lives on Facebook).

**Create (manual):** the Event editor gains a **"Repeats"** panel — `weekly / every-2-weeks / monthly-by-weekday`, an interval, and an end (until-date or count). On save it **generates the occurrences as flat events**. No rrule-on-read; the dates are real rows.

**Manage:** series-level actions in the editor and list table —
- **Edit this & future** (re-stamp forward occurrences from the master), **edit just this one** (detaches it: `_gasf_series_role` stays `occurrence` but pinned from series edits), **delete this one**, **delete the series**.
- A list-table "Series" filter/column so 52 Euchre nights collapse to one manageable group.

**FB recurring unification:** Facebook recurring events (the `event_times[]` expansion in §6.2) use the **same** `_gasf_series_id` mechanism — one FB recurring event → one series of flat occurrences sharing a cover. So manual and FB recurrence are one concept, one set of bulk actions, one mental model. (This subsumes today's `gasf_mec_recurring_parent` hack.)

**Why flat-but-grouped:** keeps every downstream surface (month grid, print, JSON-LD, iCal, kiosk feed) querying simple individual events with real dates, while giving maintainers "manage the whole series" ergonomics. Best of both, no recurrence engine.

---

## 5. Display Layer

### 5.0 Rendering approach (NOT "solely CSS")
Three deliberate layers — the opposite of MEC's JS-rendered black box (which is *why* MEC couldn't print):
1. **Semantic, server-rendered HTML** — every view outputs clean structured markup on the server. This is what makes it printable, SEO-indexable, fast, and consumable by the kiosk as data rather than scraped HTML.
2. **CSS custom properties** for all look & theming — colors/fonts/spacing are variables that inherit the WordPress theme *and* the kiosk theme tokens, so admins retheme without touching code and no build step runs on the server.
3. **A thin layer of vanilla JS** for interaction only — month prev/next nav, signage auto-advance. No framework, no external calendar library. JS *enhances* working server HTML; with JS off, the current month still renders and prints.

So: CSS owns appearance, but markup is structured HTML and behavior is a sprinkle of JS. Not solely CSS, and crucially not solely JS.

### 5.1 Renderers (shortcodes + blocks)
Single shortcode with a `view` attribute, plus a matching Gutenberg block:
```
[gasf_events view="month"]   → month grid (pages 6058, 8647)
[gasf_events view="list"]    → upcoming list (page 13415)
[gasf_events view="tile"]    → signage tiles (page 12730)
```
- **Month grid:** server-rendered month with prev/next nav (query-param, progressively enhanced by JS), event chips per day linking to `/events/<slug>/`. Touch-friendly. Day-cell overflow shows "+N more" on screen.
- **Upcoming list:** next N events (`from = now`), date-grouped, image + title + time.
- **Signage tile:** full-bleed auto-advancing tiles (cover image + title + date) for the hallway display; no interaction; long cache; designed for the Surface Hub idle/attract context. **Highest stability priority — must not flicker during cutover.**
- **Querying:** all views query `gasf_event` by `_gasf_start_ts >= now` (or month bounds), ordered by `_gasf_start_ts` (numeric `meta_query`).

### 5.2 Single-event page
Port Module G (`single-mec-events.php`) to a plugin-owned `template_include` (priority 100, guarded by `is_singular('gasf_event')`), reading `_gasf_*`. Keeps: branded layout, status banner (cancelled/postponed), inline CSS (plugin dir may be outside web root). Survives theme reinstalls. Add-to-calendar buttons come from the native helper (§5.6).

### 5.3 Custom shortcodes — full rename + aliases ("all in")
Rename to the `gasf_*` namespace with internals reading `gasf_event`/`_gasf_*`:
`[mec_upcoming_dates]→[gasf_upcoming_dates]`, `[german_dinner_events]→[gasf_dinner_events]`, `[bayern_match_events]→[gasf_bayern_events]`, `[world_cup_schedule]→[gasf_world_cup_schedule]`. No `mec_*` names survive in new code.

**Status (2026-07-04):** `gasf_upcoming_dates`, `gasf_dinner_events` and `gasf_bayern_events` are implemented (the latter two as thin presets over the generic filter). Rather than registering back-compat aliases, the old tags' page content was migrated directly and the old GASF-Utilities modules deleted (Utilities v1.5/v1.6). `[world_cup_schedule]` stays in GASF-Utilities — it already queries `gasf_event` natively.

### 5.4 Theming
Plugin CSS uses CSS custom properties (`--gas-color-*`, `--gas-font-*`, etc.) so colors/fonts inherit the site theme and the kiosk theme tokens. Admins restyle via variables; no rebuild.

### 5.5 Printing the month view (FIRST-CLASS — fixes the MEC pain point)
MEC's print was a hassle because its grid is JS-rendered with ad-hoc classes *inside* full theme chrome, so the browser print shredded it across pages. Native solution, three layers:

1. **Standalone print route** — `/events/print/<YYYY-MM>/` (e.g. `/events/print/2026-07/`) renders **only** a clean month grid: no site header/footer/sidebar, self-contained page, embedded print CSS, landscape, sized to **one US-Letter sheet**. Optional `&auto=1` fires `window.print()` on load. This is the reliable path — what the "Print this month" button uses.
2. **`@media print` stylesheet on the normal calendar page** — so a plain Ctrl-P from page 6058/8647 also yields a clean grid: `@page { size: landscape; margin: 0.4in }`, hide nav/chrome/links-as-URLs, force black-on-white, `print-color-adjust: exact` for category/status accents, and explicit page-break control so weeks never split mid-row.
3. **"Print this month" button** on the month view, linking to the print route for the **currently-viewed** month (carries the `m=YYYY-MM` the user navigated to, not just "today").

**Overflow handling for busy months:** on-screen day cells cap at "+N more"; the print view switches to a compact mode (smaller type, all events listed, or an auto "events continued" legend below the grid) so a full month still fits one page. **Paper default: US-Letter, landscape** (confirmed); A4 left as a settings toggle for future displays. Replaces `22-calendar-print-button.php` entirely (which only hid chrome via JS against MEC's classes).

### 5.5.1 Home-page heroes (`includes/heroes.php`, `includes/recurring-heroes.php`)
Moved here 2026-07 from GASF-Utilities — heroes advertise events and bind to them (manual heroes retire at a linked event's `_gasf_end_ts`; recurring heroes match by event title). Two admin screens under the Events menu: **Heroes** (hand-scheduled queue — newest activated entry wins, one-off WP-Cron purges the home cache at activation) and **Recurring Heroes** (event-name rules that auto-appear `lead_days` before each occurrence). The front end is the `[gas_hero]` shortcode; recurring overlays via the `gasf_hero_active_entry` filter. Gate: Events → Settings → "Heroes" (`gasf_events_heroes_enabled`, inherits the legacy `gasf_mec_enable_hero` on first read). **Public contract is deliberately unchanged from the Utilities era** — the `[gas_hero]` shortcode, the global `gasf_hero_active()`, the `gasf_hero_active_entry` filter, `gasf_hero_schedule_purge()`/`gasf_hero_entry_expires()`, the `.gasf-hero*` CSS, and all `gasf_hero_*` options — so GASF-Utilities' `37-perf.php` (which preloads the hero as the LCP element) still works untouched. Kept procedural (not a `class-*`), guarded by `function_exists('gasf_hero_active')` for a fatal-free straddled deploy.

### 5.6 Visitor "Add to Calendar" (native, first-class)
A single helper `gasf_events_add_to_calendar($event)` renders a **dropdown/button group** so any visitor can add an event to their own calendar. All targets are computed from `_gasf_start/_gasf_end` + `_gasf_*_ts` (UTC-exact, DST-correct) — no per-template URL hand-building.

**Providers covered:**
| Target | Mechanism |
|---|---|
| **Google Calendar** | `calendar.google.com/calendar/render?action=TEMPLATE&...` deep link |
| **Outlook.com** (personal) | `outlook.live.com/calendar/0/deeplink/compose?...` |
| **Office 365 / Outlook (work)** | `outlook.office.com/calendar/0/deeplink/compose?...` |
| **Apple Calendar** | served by the **`.ics` download** (no Apple web URL — `.ics` opens directly in Calendar on macOS/iOS) |
| **Download .ics** | universal fallback (Apple, Thunderbird, any client) — per-event `/?gasf-events-ical=1&event=<id>` |
| **Yahoo** (optional) | `calendar.yahoo.com/?v=60&...` |

**Placement:** primary on the single-event page; optional compact "Add" affordance in the upcoming-list rows and month-grid event popovers.

**Kiosk variant:** on the Surface Hub (touch, no keyboard, no login) the same helper can render a **QR code** of the event's `.ics`/Google link so a visitor scans it straight onto their phone — the natural hallway-kiosk form of "add to calendar."

### 5.7 Per-event SEO & Google Events (every event is a unique, fully-tagged page)
Each `gasf_event` is its own URL (`/events/<slug>/`, preserved through migration) and ships the full SEO surface. This is the single source of event structured data — **MEC's native schema stays off** (it created the duplicate-schema problem).

**schema.org `Event` JSON-LD** (drives Google's event rich results), built from `_gasf_*`:
| Property | Source / note |
|---|---|
| `name`, `description` | title / content (entity-decoded) |
| `startDate`, `endDate` | **ISO-8601 with timezone offset** (DST-correct via `wp_timezone()`, e.g. `2026-07-04T18:00:00-04:00`) |
| `eventStatus` | mapped from `_gasf_status` → `EventScheduled` / `EventCancelled` / `EventPostponed` / `EventMovedOnline` |
| `eventAttendanceMode` | Offline (club) or Online (`online_only`) |
| `location` | `Place` (club name + full address + geo) or `VirtualLocation` (uses `_gasf_online_link`) |
| **`image`** | event cover, ideally multiple ratios (16:9 / 4:3 / 1:1); **falls back to `gasf_events_default_image` when the event has no cover so `image` is never absent** (§4.3) |
| **`organizer`** | `{ @type: Organization, name, url }` from `gasf_events_organizer` (per-event override allowed) — **name + URL always emitted** |
| **`eventStatus`** | always emitted; `_gasf_status` → `EventScheduled` / `EventCancelled` / `EventPostponed` / `EventMovedOnline` |
| **`offers`** | emitted when `_gasf_cost > 0` — `{ price, priceCurrency: USD, availability, url, validFrom }` |
| `url` | canonical event URL |

> **Non-negotiable (per requirement):** every Event block carries **organizer name, organizer URL, an image (cover or default), offers (when priced), and eventStatus**. These are wired from the settings in §4.3 + `_gasf_*` so they're present on all 860+ events, not just hand-made ones.

**Integrate into Yoast's schema `@graph`** via the `wpseo_schema_graph` filter rather than emitting a standalone block — the Event node links cleanly to Yoast's WebPage/Organization nodes and we avoid duplicate Organization/WebPage emission. (Cleaner than module 05's current buffer-patch + footer-inject approach.)

**Standard per-page meta (via Yoast, already installed):** unique `<title>` + meta description per event, canonical URL, **Open Graph** (`og:title/description/image=cover/url`, `og:type`) — important because these events get reshared to the same Facebook they came from — and **Twitter Card** tags.

**Crawlability:** CPT registered `public` + `show_in_rest` so events appear in the Yoast **XML sitemap**; Yoast breadcrumbs (Events › Event name). **Past events stay indexable** (part of the club's 70-year history; Google auto-drops past events from rich results anyway).

**Validation gate:** Google Rich Results Test pre-launch + the Search Console "Events" enhancement report monitored post-cutover.

**Adjacent:** the clean model + iCal feed (§7) also make the parked **Google Business Profile** events/hours sync straightforward when that project un-parks.

---

## 6. Native Facebook Importer (the rewrite)

Collapses **`mec-advanced-importer` + Modules A–F + most of H** into one clean component inside the plugin.

### 6.1 Trigger
- **Single** WP-cron hook `gasf_events_sync` on a sane interval (proposed **every 15 min**; configurable). Registered once; **no per-pageload rescheduling** (the bug Module A fixed). Optionally backed by a real cPanel cron hitting `wp cron event run gasf_events_sync` for reliability on a low-traffic site.
- Manual "Sync now" + per-event `gasf_events_fb_refresh($post_id, $dry_run)` for testing.

### 6.2 Fetch
- Graph API: `GET /{page-id}/events?fields=id,name,description,start_time,end_time,cover,event_times,is_canceled,place&time_filter=upcoming` per configured page.
- **Recurring events:** expand `event_times[]` into **one flat `gasf_event` per occurrence** (keyed `_gasf_fb_event_id = <fb_id>@<occurrence_start>`). This is already how the data looks today (876 flat events) — we just drop MEC's custom_days/`reschedule()` machinery.
- **Time window:** import upcoming + a small past grace window; ignore far-future noise (ports Module C's intent, natively).

### 6.3 Credentials
- New option `gasf_events_fb_accounts` = `[{ id, label, page_id, access_token, expire_at }]`. One-time import the existing token(s) from `mec_advimp_auth_facebook` (array keyed per page) during migration. **Long-lived page tokens; document the refresh procedure** (token longevity is the single biggest ongoing fragility — unchanged from today).

### 6.4 Upsert (FB = source of truth)
For each fetched occurrence, find existing `gasf_event` by `_gasf_fb_event_id`:
- **Create** if absent; **update** title/description/start/end/cover if changed.
- Writes `_gasf_start/_gasf_end/_gasf_*_ts/_gasf_all_day` directly — no MEC table, no schedule lib.
- **Cover sync + dedup:** port H's proven pattern — sideload cover, SHA1 the file, reuse an existing **master attachment** (`_gasf_cover_sha1`) instead of duplicating; self-heal missing files; orphan-cleanup guarded against shared-file deletion. (This is what keeps 175 Biergartens sharing one image — must be preserved.)
- **Deletion:** FB not-found for 2 consecutive cycles → set post to `draft` (ports H + the "deleted FB events auto-unpublish" rule). Avoids the MEC **trash-limbo** dedup bug entirely because dedup is by `_gasf_fb_event_id` and we draft (not trash).
- **Dedup is status-aware** (fixes MEC's status-blind dedup): matching considers post_status so a drafted event can re-import cleanly.

### 6.5 What we explicitly delete vs. MEC importer
- No `request` postmeta bloat (Module F obsolete).
- No forced-`$_POST` admin hijack (Module B obsolete).
- No per-init cron churn (Module A obsolete).
- No `_4UX_mec_events`/`reschedule()` writes (Module D obsolete).
- No SQL dedup sweep (Module E obsolete) — upsert is deterministic by `_gasf_fb_event_id`.

---

## 7. Public Feeds / API (for kiosk + calendar-sync)

The kiosk (this repo's Phase 3, *not yet built*) currently plans to "sync from WordPress MEC." Replace that with a **clean, owned contract** the kiosk consumes — better than scraping MEC endpoints:

- **REST:** `GET /wp-json/gasf-events/v1/events?from=&to=&limit=&order=&fields=&updated_since=` → normalized JSON (id, title, slug, url, start, end, all_day, status, image, description, organizer, venue, series_id, source, modified). `updated_since` enables **incremental** kiosk sync. Cache-friendly. Full reference: [`REST-API.md`](REST-API.md). *(Lock this JSON contract before the kiosk Phase 3 is built — see §10.)*
  - **`fields` (v0.22.0)** — either an include list (`fields=title,start`) or an exclude list (`fields=-description`); mixing the two, or naming a field that doesn't exist, is a 400 rather than a guess. Nothing is force-included, so the response holds exactly what was asked for. The no-`fields` payload is unchanged and stays the frozen contract; new fields only ever append. Fields are built individually, so an unrequested `description` never runs `do_shortcode()` and an unrequested `image` never touches the attachment tables — the saving is real work skipped, not just bytes trimmed.
  - **`order` (v0.22.0)** — `asc` (default, soonest first, so `limit=1` is "the next event") or `desc`.
  - **`event` / `contains` / `instance` (v0.24.0)** — `event=fcbayern|dinner` are named presets resolving to the **Settings** title filters the matching shortcodes already use, so the API can't drift from the site; `contains=` is literal title text for everything else. `instance=N` is 1-based and returns exactly one event (`instance=1` = the next match, `2` = the one after), still wrapped in an array so the response shape never changes. Out of range is `[]`, not a 404. Mutually exclusive combinations (`event`+`contains`, `instance`+`limit`) are 400s rather than one silently winning, and `?event=X?instance=2` — a second `?` where an `&` belongs — is a 400 that echoes the corrected URL. The title match runs as a prepared `post_title LIKE` in SQL, **not** the shortcodes' fetch-200-then-filter-in-PHP: filtering after the fact would silently make `instance` wrong as soon as the Nth match fell outside the fetch window.
- **iCal — per-event:** `/?gasf-events-ical=1&event=<id>` → powers the Apple/"Download .ics" add-to-calendar (§5.6).
- **iCal — whole-calendar subscription:** `/?gasf-events-ical=1` (optionally `webcal://…`) → a **subscribable feed** members add to their phone once and it stays updated. Replaces MEC's `?mec-ical-feed=1`. (In-core Google/Eventbrite syndication reads events directly, not via this feed — §7.1.)

This makes the kiosk's event sync — and any external subscriber — a first-class, documented feed rather than an HTML scrape.

### 7.1 Outbound syndication (Eventbrite + Google Calendar) is folded **into** the core plugin
**Decision (revised):** syndication lives in the core plugin, not a sibling — so there's one cohesive admin (the All Events list + Bulk Actions, §4.5) and zero leftover plugins/MU-hacks. Concretely:
- **Eventbrite** publishing is a **Bulk Action** ("Publish to Eventbrite") + per-row action on the All Events list. The Eventbrite API client + a small **publish queue/cron + retry** (ported from `gasf-event-calendars`) run inside the core plugin; results land in `_gasf_eventbrite_*` and the All Events "Eventbrite" column. Because the core plugin is a **regular plugin** (activation lifecycle), it can host the queue/cron that previously justified a separate plugin.
- **`gasf-event-calendars` is absorbed and retired** — its Eventbrite client moves in; its `class-mec-reader.php` is **dropped entirely** (core owns the events, no adapter needed).
- **Google Calendar** sync becomes a **second outbound destination** in the same in-core syndication component (the `26-calendar-sync.php` service-account/JWT logic moves in, reading native events directly). It's parked/OFF, so it lands in a later phase; retired as an MU hack either way.
- **Exports** (CSV / ICS Bulk Actions) reuse the §7 ICS generator — no external service, just a download.

#### 7.2 Outbound sync: GASF Calendar → any destination (built P7, destination-agnostic)
**Generalized (2026-06-28 per user): the source of an event is irrelevant.** Publishing is **`gasf_event` → outbound destinations**, where any event (manual, Facebook, ICS, …) can be published, and destinations are **pluggable** — Eventbrite is the first; others register via the `gasf_events_destinations` filter.
- **Registry:** `interface Publish_Destination` + `Destinations::all()` (filterable). Per-event state is generic: `_gasf_published[<dest>] = { id, url, status, synced_at, error }` (no EB-hardcoded keys).
- **Mark → reconcile:** the "Publish to <dest>" bulk action marks intent; `Syndication::reconcile()` pushes and records state.
- **Stay-in-sync:** thereafter **any** save of that event re-pushes to every destination it's published to — and because the **feed importers write via `Event_Ingest` → `wp_insert_post`**, a Facebook/ICS update fires the same `save_post_gasf_event` hook a manual edit does. One path, debounced on `shutdown`. So an FB edit, an ICS refresh, or a human edit all flow outward identically.
- **Deletion / cancellation:** trashed/drafted/`cancelled` → the listing is **removed/unpublished** at each destination (`reconcile()`'s `is_public` check).
- **Pinned events (`_gasf_sync_locked`):** the pin blocks the **inbound** feed→WP overwrite (you own the content), but **WP → destinations still mirror** whatever WP holds.
- **Caveat (surfaced, not silent):** Eventbrite restricts editing once tickets have sold; the push records `status = error` + message in `_gasf_published` and the All Events **Published** column shows a ⚠ rather than failing quietly.
- **Eventbrite v1 scope:** maps name + start/end (UTC) + a free ticket, then publishes; rich description (structured content) + venue creation are follow-ons.

---

## 8. Migration Plan (860 publish + 2 draft + 8 trash)

### 8.1 Strategy: in-place conversion
1. Register `gasf_event` initially with a **temporary slug** (`/gasf-events/`) so the new plugin runs **in parallel** with live MEC for validation.
2. **Migration script** (idempotent, dry-run first):
   - For each `mec-events` post: derive `_gasf_start/_gasf_end` from `mec_start_date` + `mec_*_time_hour/minutes/ampm` (DST-correct via `wp_timezone()`); compute `_gasf_*_ts`; map `mec_allday/mec_hide_time/mec_hide_end_time/mec_event_status/mec_more_info*/mec_cost/mec_advimp_facebook_event_id` → `_gasf_*`.
   - **Do not delete `mec_*` meta** during validation (parallel-run safety / rollback).
   - Cover/`_thumbnail_id` retained as-is (no image re-download).
3. Validate: every view renders, single pages, schema, REST + iCal feeds, spot-check 20 events incl. all-day + cancelled + a Biergarten series.
4. **Cutover** (single maintenance window):
   - Convert post_type `mec-events` → `gasf_event` (preserves IDs/slugs/dates/thumbnails).
   - Switch `gasf_event` rewrite slug to **`events`**; `flush_rewrite_rules()`.
   - Swap the 4 calendar pages' shortcodes to `[gasf_events ...]`; repoint the 5 custom shortcodes (already deployed, gated).
   - Deactivate MEC + `mec-advanced-importer`; disable Module A–F/H gates.
   - Enable native `gasf_events_sync` cron.
5. Soak 48–72h with FB sync monitored; then decommission (§9).

### 8.2 URL / SEO preservation
Slugs unchanged + same `/events/` base → existing URLs and Yoast/Google indexing preserved. Add belt-and-suspenders 301s only if any slug must change (none expected).

### 8.3 Trash handling
The 8 trashed `mec-events` (some are the known trash-limbo artifacts) are reviewed during migration: real-but-stuck events restored as drafts; genuine junk purged. Native dedup prevents recurrence of the limbo bug.

---

## 9. Cutover, Decommission & Rollback

**Decommission (after soak):**
- Delete `mec-advanced-importer` plugin; remove Modules A–F + H from `gasf-muplugin`.
- Drop `_4UX_mec_events`, `_4UX_mec_dates` tables (after a DB backup).
- Remove now-stale `mec_*` postmeta in a final sweep (after rollback window closes).
- Update memory files: `gas-mec-event-schema`, `gas-mec-datetime-bug`, `gas-codesnippets-muplugins`.

**Rollback (within soak window):** `mec_*` meta + MEC tables still intact → reactivate MEC + importer, disable native cron, revert `gasf_event` slug + the 4 pages' shortcodes. Fast and complete because nothing destructive happens until decommission.

---

## 10. Risks & Open Questions

| Risk | Severity | Mitigation |
|---|---|---|
| FB page-token expiry / Graph API changes | **HIGH (ongoing)** | Same risk as today; document token refresh; admin warning when `expire_at` near |
| DST correctness in date migration | MED | `wp_timezone()` throughout; validate spring/fall boundary events |
| Signage page flicker during cutover | MED | Cut over signage shortcode last; long cache; pre-warm |
| 860-event conversion errors | MED | Dry-run + idempotent script + parallel-run validation before in-place convert |
| Yoast / schema duplication | LOW | Single schema injector (ours); ensure MEC native schema stays off |
| Event-table growth (keep-all + hourly FB) → thousands over years | MED | Index `_gasf_start_ts`; date-bound all grid/list queries; cache views |
| Kiosk Phase 3 not yet built | LOW (opportunity) | Define + freeze REST/iCal JSON contract now so kiosk consumes it cleanly |

**Resolved (2026-06-27):**
- **Display approach:** semantic server-rendered HTML + CSS custom properties + thin vanilla JS (§5.0) — not solely CSS, not a JS black box.
- **Naming:** full `gasf_*` rename of meta and shortcodes, with back-compat aliases for old tags retired after a grace period (§5.3).
- **Printing:** first-class — standalone print route + `@media print` stylesheet + per-month print button; **US-Letter landscape** (§5.5).
- **Visitor add-to-calendar:** native helper — Google, Outlook.com, Office 365, Apple/.ics, Yahoo, universal .ics; QR variant on the kiosk (§5.6).
- **Admin editor:** maintainer-friendly add/edit/delete meta box; manual events shielded from FB sync via `_gasf_source` (§4.5–4.6).
- **Sync pin:** **whole-event** for v1 (`_gasf_sync_locked`) + auto-pin-on-edit + deletion exemption; automatic per-field dirty-tracking reserved as a no-migration v2 (§4.6).
- **Per-event SEO:** unique page each; Event JSON-LD via Yoast `@graph` **always carrying organizer name+URL, image (cover→default), offers (when priced), eventStatus**; OG/Twitter/canonical/sitemap; past events stay indexable (§5.7).
- **Recurring events:** first-class **flat-but-grouped series** (`_gasf_series_id`) with a "Repeats" generator + series-level edit/delete; FB recurrence uses the same mechanism (§4.7).
- **Default image:** site-wide fallback cover (`gasf_events_default_image`) used in display **and** schema so no event is image-less (§4.3).
- **Organizer:** global setting (name + URL) with per-event override (§4.3).
- **Whole-calendar subscribe feed:** offered (`webcal://`), §7.
- **Retention:** keep all past events (history + SEO); index `_gasf_start_ts` (§10 risk row).
- **All Events list + Bulk Actions:** MEC-style list with multiselect → **Publish to Eventbrite / Move to Trash / Export CSV / Export ICS** (§4.5).
- **Outbound syndication folded into core:** Eventbrite (bulk action) **and** Google Calendar (later) live in the core plugin; `gasf-event-calendars` is **absorbed & retired** (§7.1).
- **Three-way sync:** **FB → WP → Eventbrite** kept in sync automatically — FB or manual edits auto-update the EB listing; trash/cancel propagates; pinned events still mirror WP→EB (§7.2).
- **Per-event view stats:** native cache-safe beacon counter (`_gasf_views`), web/kiosk split, surfaced as a Views column + "Stats" row action (§4.5.1).
- **All Events columns/filters:** Added + Event-date columns, Recurring ✓ column, Views column; text search + date-range filter (§4.5).

**Open questions for planning:**
1. Repo: new standalone `flinchbot/GASF-Events` plugin (clean lifecycle/activation hooks — hosts the Eventbrite/Google queue+cron). Recommend yes; `gasf-event-calendars` is absorbed into it.
2. Multiple FB pages now or single? (Schema supports many; start with the one in use.)
3. Multi-day / boundary-crossing events: rare per the club, so support **correctly but minimally** (event spans each covered day in the grid; no extra UI). Confirm "minimal" is fine.
4. Caching/invalidation: month/list/signage + REST need purge-on-save/purge-on-sync against Cloudflare/SpeedyCache/Endurance — confirm we wire explicit purges.
5. Native **sync-status dashboard** (last run, created/updated/drafted counts, errors, token-expiry warning) — confirm it replaces the old GASF-Utilities "Event Calendars" panel.
6. Migration: explicit spot-check that the 79 "rich" events don't surface a `hourly_schedules` timetable/agenda anywhere before we drop that meta.
7. View-count privacy/accuracy: dedupe by session/IP window, or raw hit count? (Recommend simple deduped hits, bots/admins excluded.)

---

## 11. Proposed Phase Breakdown (for a GSD milestone)

Run as its own GSD milestone in the WordPress/plugin repo (not the kiosk repo):

1. **P1 — Plugin scaffold + `gasf_event` model + admin editor** (CPT, maintainer-friendly Event Details meta box with date/time pickers + status; venue/organizer/default-image settings; list-table columns/filters, manual add/edit/delete, role caps, `_gasf_source` provenance — §4.3/4.5/4.6).
2. **P2 — Recurring series** ("Repeats" generator, `_gasf_series_id`, series-level edit/delete, list-table grouping — §4.7).
3. **P3 — Migration script** (dry-run → parallel-run copies under temp slug; validation harness; 79-rich-event spot-check; index `_gasf_start_ts`).
4. **P4 — Display layer** (month grid, list, signage tile, single-event template; first-class print route + print stylesheet; visitor add-to-calendar incl. kiosk QR; default-image fallback; repoint/rename custom shortcodes; **schema injector with organizer+image+offers+eventStatus**).
5. **P5 — Native FB importer** (cron, fetch, upsert, cover SHA1 dedup, FB-recurrence→series, deletion handling, token import, sync-status dashboard).
6. **P6 — Multi-feed ingest & Feeds router** (DONE) — see §7.3. Shared `Event_Ingest` core (FB + ICS + manual write through one dedup/provenance path); ICS parser + Google Calendar destination (both ported from module 26); unified **Feeds** page with per-feed destinations (GASF / Google / both); absorbs the P5 FB importer + the legacy Calendar Sync module. Gated OFF.
7. **P7 — All Events bulk actions + Eventbrite + view stats** (Bulk Actions: Publish to Eventbrite, Trash, CSV, ICS; port Eventbrite client + queue into core; **three-way FB→WP→EB auto-sync** incl. trash/cancel propagation; cache-safe view-counter beacon + Stats panel — §4.5/§4.5.1/§7.1/§7.2).
8. **P8 — Public feeds** (REST w/ `updated_since`; per-event + subscribable iCal).
9. **P9 — Cutover + decommission** (in-place convert, slug switch, cache-purge wiring, deactivate MEC/importer, **absorb & retire `gasf-event-calendars` + module 26 / Calendar Sync**, drop tables, monitoring, rollback drill, memory updates).

### 7.3 Multi-feed ingest & the Feeds router (built in P6)
Decision (2026-06-28): generalize ingestion so the GASF Calendar can be fed by **many** sources, and turn the legacy one-way "MEC→Google" Calendar Sync into a **feed router** — all inside the plugin (Calendar Sync module 26 is absorbed, not kept).
- **One ingest core — `Event_Ingest`.** Facebook, ICS feeds, (later) Eventbrite-inbound, and manual edits all upsert through one path. Dedup is namespaced per source via `_gasf_source_uid = "<source>|<uid>"` (so feed A can't collide with feed B); provenance in `_gasf_source` + `_gasf_source_feed`; the pin rules, cover SHA1 dedup, series grouping, and auto-pin snapshot all live here once.
- **Feeds.** Each feed = `{ type: facebook|ics, label, source (page+token | ics url), enabled, destinations }`. The 15-min cron runs every enabled feed.
- **Destinations (the tickboxes you asked for): GASF Calendar, Google Calendar, or both.** GASF = `Event_Ingest::upsert` + miss-prune; Google = `Google_Calendar::sync_source` (service-account JWT, idempotent insert/update/delete keyed on `extendedProperties.private`, reimplemented from module 26). The ICS parser is reused for both inbound ingest and the outbound Google body.
- **Loop/dedup safety:** every ingested event carries its source + per-source UID; the Google marker is `gasf_mgr=gasf-events` (distinct from the old module's `calsync`) so the two never fight during parallel run.
- **One admin home:** Events → **Feeds** (master gate, Google calendar id, per-feed destinations, dry-run/run, status + token-expiry + log, one-click import of FB tokens + Calendar-Sync ICS sources). Replaces the P5 sync page and the calsync tab.

#### 7.3.1 Cross-feed duplicate merge (built 2026-09-11)
Several feeds write to **one** Google Calendar (GASF, GCESV, GASCF), so a co-hosted event arrives two or three times and fills the merged view. `Event_Merge` collapses them.

- **Scope: the Google destination only.** It is the only place many feeds share one calendar. The GASF Calendar dedups per-source UID in `Event_Ingest` and currently takes exactly one feed, so it cannot collide. The matcher is destination-agnostic so that stays true if the ICS feeds are ever pointed at `dest_gasf`.
- **A match needs start AND end AND title — all three.** This is not belt-and-braces. Live data:

```
GASF   Oktoberfest          12:00 → 22:00
GCESV  Oktoberfest GASF     12:00 → 22:00   same event, merged
GCESV  Oktoberfest Tampa    17:00 → 19:00   different org, kept
GCESV  Wellen Park O'fest   12:00 → 14:00   different org, kept
```

  "Oktoberfest Tampa" contains "Oktoberfest" and starts on the identical minute, so title+start alone merges two unrelated festivals. The **end time is the only discriminator**. Tolerance is ±30 min on each of start and end; a missing end is treated as ending at the start, so it never silently widens a match.
- **Priority is positional.** Feeds flagged `definitive` sort first (Feeds → edit → *Definitive*), then config order. The winner keeps its title, times and identity; losers are dropped from their feed's list and their descriptions appended to the winner under a `— <feed label> —` attribution line. Unattributed text appearing inside a description is impossible to account for later.
- **Deletion is self-healing.** `sync_source` removes managed events a feed no longer carries, so a duplicate suppressed upstream deletes its own stale Google copy on the next run. Nothing needs unwinding.
- **Bucketed per calendar id** — feeds aimed at *different* calendars are never duplicates of one another, however alike their events look.
- **Single-feed "Sync now" still merges.** It pulls the definitive feeds sharing that calendar in as comparison-only groups (`sync => false`, never written), otherwise syncing one feed alone would re-insert the duplicates the last full run collapsed.
- **Every collapse is logged** to `$stats['merges']` (kept/dropped title, both feed labels, start) and surfaced in the sync log, so a wrong merge is visible rather than silent. A dry run shows it without writing.

---

### Appendix A — MEC → `gasf_event` meta crosswalk (for the migration script)
```
mec_start_date + mec_start_time_hour/minutes/ampm   → _gasf_start (Y-m-d H:i:s) + _gasf_start_ts (UTC)
mec_end_date   + mec_end_time_hour/minutes/ampm     → _gasf_end   + _gasf_end_ts
mec_allday                                          → _gasf_all_day
mec_hide_time / mec_hide_end_time                   → _gasf_hide_time / _gasf_hide_end
mec_event_status                                    → _gasf_status (+ _gasf_status_reason, _gasf_online_link)
mec_more_info / mec_more_info_title                 → _gasf_more_info_url / _gasf_more_info_title
mec_cost (if > 0)                                   → _gasf_cost
mec_advimp_facebook_event_id                        → _gasf_fb_event_id
mec_location_id / mec_organizer_id                  → (dropped; venue/organizer are settings)
_thumbnail_id                                       → (unchanged)
mec_repeat_* , mec_date , _4UX_mec_events/_dates    → (dropped; flat single events only)
```
