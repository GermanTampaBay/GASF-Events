# Code review — GASF Events v0.33.1 (2026-10-28)

Scope: every PHP file under `includes/`, `templates/`, `admin/`, the JS, `uninstall.php`,
`gasf-events.php`, and the three docs. Review questions asked: bugs, API completeness,
missing features, WordPress safety. Each finding below says how it was verified
(file:line, or a check against current WordPress core / Meta docs) so nothing here is
a guess. "Verified" means the code path was read end to end; "likely" means it depends
on something outside the repo (host config, live data) that could not be checked here.

Severity: **P1** = breaks something visible or corrupts data; **P2** = wrong under
realistic conditions; **P3** = cleanup / hardening / drift.

---

## 1. Bugs

### P1-1 — `assets/js/vendor/alpine.min.js` is never committed, so every zip-based install ships without Alpine

- `includes/class-assets.php:30` registers and enqueues `assets/js/vendor/alpine.min.js`.
- The file is absent from the repo. `git check-ignore -v` shows why: `.gitignore:4` is
  `vendor/`, and a bare `vendor/` pattern matches at any depth, including
  `assets/js/vendor/`.
- `includes/class-updater.php` installs from GitHub's `main.zip`, which cannot contain an
  untracked file. WordPress's plugin upgrader clears the destination folder before
  copying, so even a hand-placed copy is deleted on the next update.
- Everything behind `x-data` stops working on an install made this way: the signage tile
  rotation (`templates/view-tile.php:20`, the "highest stability priority" surface in
  ARCHITECTURE §5.1), the month-grid hover popups, the list live filter, and the
  "Add to calendar" dropdown on single pages (`templates/single-gasf_event.php:103`).
  The server-rendered HTML still shows, so it fails quietly: tiles freeze on slide one.
- **Not verified:** whether the live site is a git checkout (where an ignored file
  survives `git pull`) or a zip install. `class-post-type.php:30` mentions git-pull
  updates, so both paths may be in use. Check the live site for
  `wp-content/plugins/GASF-Events/assets/js/vendor/alpine.min.js`.
- Fix: change `.gitignore` to `/vendor/` (root only), commit `alpine.min.js` 3.14.8, and
  consider a release check that every registered asset exists (see §4, tests).

### P1-2 — "Duplicate" copies the Eventbrite publish state, so the copy can unpublish or overwrite the original's listing

- `includes/class-event-duplicate.php:37-57` (`SKIP_META`) omits `Meta::PUBLISHED`
  (`_gasf_published`) and `Meta::PUBLISH_INTENT`, so `copy()` (line 131) copies them.
- `includes/class-syndication.php:96-104` enqueues any save of a post that has
  `_gasf_published`. `reconcile()` (line 166) then reads `$state['id']`, the original
  event's Eventbrite id.
  - Save the draft copy once (changing its date, which is the whole point of
    duplicating): `is_public` is false (line 177) → `remove($state)` →
    `POST /events/{original_id}/unpublish/` (`class-eventbrite.php:120`). The
    **original's** Eventbrite listing is unpublished.
  - Publish the copy: `push($event, $state)` → `POST /events/{original_id}/` with the
    copy's title and dates. The original's listing is overwritten.
- Only bites when Eventbrite publishing is enabled and the source event was published
  there, which is exactly the "same event, new date" case the feature exists for.
- Fix: add `Meta::PUBLISHED` and `Meta::PUBLISH_INTENT` to `SKIP_META`.

### P1-3 — A pinned feed event can never be unpinned from the editor once it has been edited

- `includes/class-meta-box.php:288-299`: on save, the pin is written from the checkbox,
  then if the checkbox is **unchecked** and the stored snapshot differs from the
  current title/content/start/end (`Event_Ingest::snapshot_post`,
  `class-event-ingest.php:142`), the pin is set back to 1.
- The snapshot is only refreshed by a sync (`class-event-ingest.php:124`), and a sync
  skips pinned events (line 45). So after any edit to those four fields the snapshot is
  permanently stale, and unticking "Pin" re-pins on the same save.
- ARCHITECTURE §4.6 promises a "Resume syncing" path. It does not exist, and the only
  control that could provide it is defeated by the auto-pin.
- Fix: treat an explicit untick as intent. Read the previous `SYNC_LOCKED` value before
  writing; if it was 1 and the box is now unchecked, clear the pin and clear
  `FB_SNAPSHOT` (so the next sync re-adopts the feed values) and skip the auto-pin check.
  Only auto-pin when the pin was already 0 before this save.

### P2-1 — Publishing a recurring master does not publish its occurrences

- `includes/class-series.php:103` copies `post_status` from the master only at
  creation. `apply_master_to_occurrence()` (line 120) updates title and content, never
  status.
- Create a repeating event as a draft, then publish it: the master goes live, the
  generated occurrences stay drafts. Nothing in the UI says so.
- Related: `sync_from_master()` only ever creates; shortening `until` or lowering the
  count leaves the extra future occurrences in place, and changing the master's weekday
  orphans every occurrence (they are keyed by date string, line 79).
- Fix: when `apply_future` is set, or when the master transitions to `publish`, also
  update the occurrences' status; consider trashing future occurrences that no longer
  match the rule.

### P2-2 — `GET /events/changes` can silently lose updates

- `includes/class-rest.php:435`: `now` is computed **after** both queries run. An event
  modified between the query and that timestamp is never reported: the consumer's next
  `since` skips it.
- Line 398-405: `updated` is capped at 500 with no cursor. If more than 500 events were
  modified since `since` (a migration pass, a feed run that touches every event, or a
  series regeneration), the rest are dropped and `now` moves past them anyway.
- Fix: compute `now` first, before querying. When the result is truncated, set the
  returned `now` to the `post_modified_gmt` of the last item returned, or add a
  `truncated: true` flag so the consumer re-polls.

### P2-3 — Facebook Graph API v19.0 reached end of availability on 2026-05-21

- `includes/class-fb-client.php:16` hardcodes `v19.0`. Meta's changelog lists Graph API
  v19.0 as expiring May 21, 2026, and the v25.0 announcement repeats that date.
- Meta's versioning policy routes calls to an unavailable version to the oldest
  available one, which is why the sync may still appear to work. That is also how
  breaking changes arrive silently: the `events` edge now behaves as whatever version
  Meta chose, not the one the code was written against.
- Fix: pin a current version (v25.0 as of February 2026), re-test the `events` fields
  used on line 32, and record the version's expiry date in `docs/` so it is bumped on a
  schedule rather than after a breakage.
- Sources: [Meta Graph API versions changelog](https://developers.facebook.com/docs/graph-api/changelog/versions/),
  [Introducing Graph API v25.0](https://developers.facebook.com/blog/post/2026/02/18/introducing-graph-api-v25-and-marketing-api-v25/).

### P2-4 — Blank end time defaults to end = start, which breaks three downstream consumers

- `includes/class-meta-box.php:237-243`: no end date → end date = start date, and no end
  time → end time = start time. The stored event is zero-length.
- `includes/class-hours-sync.php:246` skips zero-length events ("no end time"), so the
  Business Profile hours ignore them.
- `includes/class-eventbrite.php:130-138` sends `end == start`; Eventbrite rejects an end
  that is not after the start, so the publish lands in the error column.
- The ICS export emits `DTEND == DTSTART`, which some clients show as a 0-minute event.
- Fix: either require an end time in the meta box, or default to a sane duration
  (start + 2 h, which `heroes.php:82` already assumes for exactly this case).

### P2-5 — The `/events/` archive and the "All events" back link show the theme's default archive

- `includes/class-post-type.php:59` sets `has_archive => true`. No `pre_get_posts` hook
  touches the front-end archive, so `/events/` renders the theme's `archive.php`:
  ordered by publish date, past events included, no calendar.
- `templates/single-gasf_event.php:113` links "← All events" to that archive.
- Fix: either hook `pre_get_posts` for `is_post_type_archive(GASF_EVENTS_CPT)` to order
  by `_gasf_start_ts` and filter to upcoming, or point the back link at the calendar
  page and set `has_archive` to the calendar page's slug.

### P3 — smaller items (each verified)

- **Deactivation leaves cron entries behind.** `class-plugin.php:143-146` clears three
  hooks but not `gasf_events_gbp_daily`, `gasf_events_gbp_push`, or
  `gasf_hero_activate_event` (`class-hours-sync.php:50-51`, `heroes.php:257`).
- **Uninstall is incomplete.** `uninstall.php` leaves `gasf_events_gbp_*`,
  `gasf_events_venues`, `gasf_events_type_rules`, `gasf_events_dinner_filter`,
  `gasf_events_bayern_filter`, `gasf_events_tombstones`, `gasf_events_pub_queue`,
  `gasf_events_last_run`, `gasf_events_sync_log`, `gasf_feed_ics_lg_*`,
  `gasf_hero_field_memory`, and the `gasf_events_gbp_token`,
  `gasf_events_update_check`, `gasf_events_asset_ver` transients.
- **Eventbrite logo re-uploaded on every push.** `class-eventbrite.php:82-88` uploads
  the featured image on every save of a published event (three HTTP calls), even when
  the image is unchanged. Cache the uploaded `logo_id` keyed by attachment id + file
  mtime in `_gasf_published['eventbrite']`.
- **Floating ICS times are treated as UTC.** `class-ics-parser.php:88`: a `DTSTART`
  with neither `Z` nor `TZID` is assigned `UTC`. RFC 5545 says floating time is local
  time. A feed that emits floating times will land 4–5 hours off.
- **Recurring ICS events ingest as a single occurrence.** `ICS_Parser::to_norm` drops
  `RRULE`/`EXDATE`/`RECURRENCE-ID` for the GASF destination (only Google gets the
  rrule, `class-feeds.php:393`). A weekly event from an ICS feed appears once.
- **Outlook deep links omit the timezone.** `class-add-to-calendar.php:136-137` sends
  `startdt=Y-m-d\TH:i:s` with no offset; Outlook interprets it in the viewer's zone.
  Use `format('c')` (both Outlook endpoints accept an offset).
- **`fputcsv()` without `$escape` is deprecated in PHP 8.4.** `class-bulk-actions.php:86,93`.
  Pass the explicit default (`','`, `'"'`, `'\\'`) or `''`.
- **Stale comment.** `class-settings.php:252` says `sanitize_hex_color()` lives in
  wp-admin. It is in `wp-includes/formatting.php` in current core (verified against
  trunk), which is why `templates/view-upcoming.php:16` works on the front end.
- **`changes` reports never-published drafts as removed.** Harmless noise for the
  consumer, but every duplicate draft (`class-event-duplicate.php`) shows up in
  `removed`.
- **Series menu "Edit the series" link** (`class-meta-box.php:157-160`) uses the first
  `master` member; FB-sourced series have no master (`class-event-ingest.php:114-116`),
  so FB occurrences never get the link. Cosmetic.

---

## 2. Is the public API fleshed out?

The parts that exist are unusually well designed: explicit 400s instead of silent
guesses, a frozen default payload, field selection that skips real work, a positional
range route, and a tombstone-backed delta feed. The gaps are in what the payload
carries, not in how it is served.

### Missing from the event object (the kiosk cannot render these correctly today)

| Model field (`class-event.php`) | In API? | Why it matters |
|---|---|---|
| `hide_time()` / `hide_end()` | No | The site hides these times; a kiosk showing `start`/`end` will display what the site deliberately hides. |
| `status_reason()` | No | "Cancelled — rain" shows on the site, not on the kiosk. |
| `online_link()` | No | `status: online_only` with nowhere to send people. |
| `more_info_url()` / `more_info_title()` | No | Ticket links. |
| `cost()` | No | Schema emits it; the API does not. |
| `is_multiday()` | No | Derivable, but every consumer re-derives it differently. |
| `icon()` / `color()` | No | The calendar's per-event emoji and colour (`Event::type()`) are invisible to the kiosk, so it cannot match the site's look. |
| `image` sizes | Only `large` | A signage screen wants `full`; a card grid wants `medium`. Return an object `{ large, full, medium }` as a new field, keep `image` as is. |
| `image` fallback | Returns the bundled SVG when no default is set | A consumer cannot tell "no image" from "image". Add `has_cover: bool`. |
| `description` as HTML | Plain text only | Fine for the kiosk; add `description_html` (kses-filtered) if any consumer renders rich text. |
| per-event `.ics` URL | No | `Add_To_Calendar::ics_url()` exists; one line to expose. |

All of these are additive (append to `FIELDS`), so they do not break the frozen
contract.

### Missing query parameters

- `status=` (e.g. `status=-cancelled`): today a kiosk must fetch cancelled events and
  filter them itself.
- `source=` and `series_id=`: both are in the payload but not filterable.
- `search=` for a free-text match beyond the title (`contains` is title-only).

### Protocol-level

- **No `ETag` / `Last-Modified`.** `REST-API.md` documents that the host rewrites
  `Cache-Control` to `no-store`, so every poll is a full DB hit. Conditional GETs are
  the fix the plugin controls: hash the response body (or use the max
  `post_modified_gmt`) as an `ETag`, return 304 on `If-None-Match`.
- **`/events/changes` checkpoint race and 500-cap** (P2-2 above).
- **Route `args` carry no `type`/`description`/`schema`.** `OPTIONS /gasf-events/v1/events`
  returns almost nothing, so the API is not self-describing. Adding `type`, `enum`,
  and `description` to each arg in `routes()` is cheap and gives the kiosk developer
  discovery for free.
- **`POST /view` has no abuse cap beyond 300/min site-wide** (see §4). Fine as a counter;
  document that it can be gamed.

### Things worth not adding

Pagination beyond `limit`/range, write endpoints, authentication. For a club calendar
with a few hundred events a year, the current read-only shape is right.

---

## 3. Missing features

Ordered by how much they matter to the club, with the evidence for each.

1. **Open Graph / Twitter / meta description tags for event pages.** ARCHITECTURE §5.7
   says these come "from the theme". v0.33.1 removed the Yoast integration because no
   SEO plugin is installed. Nothing in the plugin emits `og:title`, `og:image`,
   `og:description`, `og:url`, `twitter:card`, or `<meta name="description">`
   (grep for `og:` returns nothing). Events are re-shared to Facebook; without
   `og:image` the share preview falls back to whatever the theme does. This is a small
   `wp_head` action guarded by `is_singular(GASF_EVENTS_CPT)`.
2. **Page-cache purge on save/sync.** `heroes.php:14-16` documents a 24-hour nginx page
   cache and purges it via `do_action('epc_purge')` when a hero activates. No event
   save, status change, or feed sync purges anything, so a cancellation can show as
   "Scheduled" on the calendar page for up to a day. ARCHITECTURE §10 lists this as
   open question 4; it is still open. Hook `save_post_gasf_event`,
   `transition_post_status`, and the end of `Feeds::run()` to the same purge.
3. **Kiosk QR code for add-to-calendar** (§5.6) is specified, not built.
4. **"+N more" overflow in the month grid** (§5.1, §5.5) is specified, not built; the
   JS at `gasf-events.js:66` even references the toggles. `view-month.php` renders
   every chip and the print view (`print-month.php:61`) hard-caps at 5.
5. **Gutenberg block** (§5.1) is specified, not built; shortcode only.
6. **A "Pinned" column / filter in All Events.** Pinned events silently stop syncing;
   there is no list-level way to see which ones are pinned.
7. **Recurring ICS expansion for the GASF destination** (P3 above).
8. **Series: remove future occurrences when the rule shrinks; publish occurrences with
   the master** (P2-1).
9. **Automated checks.** There is no `phpcs.xml`, no PHPStan, no PHPUnit, no GitHub
   Action. The updater installs whatever is on `main`, so a syntax error pushed to
   `main` reaches production on the next update check (every 15 minutes,
   `class-updater.php:178`). A minimal CI that runs `php -l` on every file, PHPCS with
   WordPress-Coding-Standards, and a check that every path passed to
   `wp_register_script`/`wp_register_style` exists would have caught P1-1 and would
   catch the next one.

---

## 4. Is it safe to run on WordPress?

Short answer: yes, with two caveats (auto-update trust and view-beacon table growth).
The admin surface is consistently guarded, remote input is treated as hostile, and SQL
is prepared. Specifics:

### Verified good

- **Capabilities and nonces.** Every `admin_post_*` handler checks a capability and
  `check_admin_referer()` (`class-feeds-admin.php:53-58`, `class-series.php:227-232`,
  `class-event-duplicate.php:85-90`, `class-venues-admin.php:54-58`, `heroes.php`,
  `recurring-heroes.php`). Settings/Feeds/Heroes require `manage_options`; the list,
  stats, and venues pages require `edit_gasf_events`, which only Administrator and
  Editor hold (`class-post-type.php:76-86`). The meta box checks nonce, autosave, and
  `edit_post` (`class-meta-box.php:220-228`).
- **SQL.** Every raw query uses `$wpdb->prepare()` (`class-rest.php:650-656`,
  `class-venues.php:96`, `recurring-heroes.php:56-116`, `class-meta.php:148-154`).
  `ensure_indexes()` interpolates only `$wpdb->postmeta` and a constant.
- **Output escaping.** Templates escape consistently (`esc_html`, `esc_attr`, `esc_url`,
  `wp_kses_post` on content). The few unescaped echoes are plugin-built HTML
  (`class-admin-list.php:96`, hero status strings) with no user input.
- **Remote content.** Feed titles are `wp_strip_all_tags`'d and descriptions go through
  `wp_kses_post(strip_shortcodes())` at ingest (`class-event-ingest.php:57-63`), and
  `Event::description()` refuses to `do_shortcode()` non-manual content
  (`class-event.php:138-151`). That closes the "shortcode injection via a Facebook
  description" hole most importers have.
- **SSRF.** ICS fetches use `reject_unsafe_urls` (`class-feeds.php:381`); the FB client
  pins pagination to `graph.facebook.com` (`class-fb-client.php:47`); cover downloads
  require https + an fbcdn/facebook/fbsbx host (`class-cover-sideloader.php:37-43`).
- **Secrets.** Google service-account and GBP credentials are files above the docroot
  (`class-google-calendar.php:25`, `class-google-business-profile.php:47`). FB and
  Eventbrite tokens are in `wp_options` with `autoload=false`, redacted from error
  strings (`class-fb-client.php:138`), masked in the UI, and deleted on uninstall. That
  is normal WordPress practice.
- **Meta exposure.** All `_gasf_*` keys register with `show_in_rest => false`
  (`class-meta.php:173,184,195`), so `/wp/v2/gasf-events` does not leak sync tokens,
  snapshots, or publish state.
- **Draft leakage.** Per-event `.ics` (`class-add-to-calendar.php:86`) and
  `GET /events/{id}` (`class-rest.php:368`) both refuse non-published posts.
- **Concurrency.** Feed runs and the publish queue use MySQL `GET_LOCK`
  (`class-meta.php:145-155`), which releases on connection death. The updater takes a
  `WP_Upgrader` lock and snapshots/restores the plugin folder
  (`class-updater.php:103-143`), written after a real incident.
- **CSV formula injection** is neutralised (`class-bulk-actions.php:81-84`).

### Caveats

1. **Auto-update trusts the `main` branch of a public repo with no integrity check.**
   `class-updater.php:25-26` reads the version from raw `main` and installs `main.zip`.
   Anyone who can push to `main` (a compromised GitHub account, a merged PR from a
   fork) ships PHP to the live site within 15 minutes. GitHub serves it over TLS, so
   the transport is fine; the trust boundary is the repo. Options, in increasing
   effort: require branch protection + 2FA on the org; install from a tagged release
   asset instead of the branch head; sign the zip and verify the signature in
   `upgrader_pre_install`.
2. **`POST /view` can grow `wp_options`.** Each counted view writes a 6-hour transient
   (`class-stats.php:74`). The 300/minute cap (line 53) bounds it, but 300/min for 6 h
   is ~108k live transients (two rows each) on a shared host with no object cache.
   `CF-Connecting-IP` is checked first, so under Cloudflare the per-IP dedup cannot be
   spoofed; if the origin is reachable directly, `X-Forwarded-For` is
   attacker-controlled (line 62). Low likelihood, but cheap to cap harder (e.g. 60/min)
   or to dedup in a single daily option keyed by hash instead of one transient per hit.
3. **Hard-coded site specifics.** `heroes.php:156-158` seeds image id 18254 and a
   germantampabay.com URL; `class-feeds-admin.php:195,380` default the page id to
   `GermanTampa`; `class-google-business-profile.php:43` embeds a Cloud project number.
   Fine for this one site, but the plugin would misbehave on any other install.
4. **`register_post_type` with `show_in_rest => true` exposes post content and author
   on `/wp/v2/gasf-events`.** That is standard for any public CPT and the content is
   already public; noted only so nobody is surprised.

---

## 5. What to do first

1. Fix `.gitignore` and commit `alpine.min.js` (P1-1). Check the live site for the file.
2. Add `PUBLISHED` and `PUBLISH_INTENT` to `Event_Duplicate::SKIP_META` (P1-2). One line.
3. Fix the unpin path in `Meta_Box::save` (P1-3).
4. Bump the Graph API version and write down its expiry (P2-3).
5. Emit OG/meta tags on event pages (§3.1) and purge the page cache on save/sync (§3.2).
6. Add the missing API fields, all additive (§2).
7. Stand up `php -l` + PHPCS in a GitHub Action before the next release (§3.9).
