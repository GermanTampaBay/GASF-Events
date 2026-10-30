# GASF Events

A lean, native WordPress events plugin for the **German American Society of Tampa** — built to replace **Modern Events Calendar (MEC)** plus its third-party Facebook importer and the stack of must-use (MU) patch modules.

> **Status:** under active construction, built in the background. It is fully **isolated** — own `gasf_event` post type, temporary `/gasf-events/` URL slug, `_gasf_*` meta, `gasf_events_*` options. It does **not** touch MEC, the MU plugins, the theme, or any production data. The live site keeps running unchanged until a single deliberate cutover. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## What it does (target)

- Owns event data as a clean `gasf_event` model (single start/end datetime, status, cover, single venue/organizer — no MEC cruft).
- Renders the four views the club actually uses: **month grid, upcoming list, signage tile, single-event page** — semantic HTML + CSS variables + minimal JS, so it prints and the kiosk can consume it.
- **First-class month printing** (US-Letter landscape), **visitor add-to-calendar** (Google/Outlook/Apple/.ics + kiosk QR), and full **Event JSON-LD** SEO.
- Native **Facebook import** (replacing the 3rd-party importer + MU band-aids) and three-way **FB → WP → Eventbrite** sync, all gated OFF until cutover.
- Recurring events as a lightweight **flat-but-grouped series**, an **All Events** admin list with bulk actions, and per-event **view stats**.

## Public API

A read-only JSON feed of published events, no key required:

```
GET /wp-json/gasf-events/v1/events?event=FCBayern&instance=2&fields=title,start,image
```

`event` presets (`fcbayern`, `dinner`) or literal `contains` text, `instance` to
pick the next / the one after, plus `limit`, `order`, `from`/`to`,
`updated_since`, and a `fields` include/exclude list.
See [`docs/REST-API.md`](docs/REST-API.md).

## Development, tests and releases

**Run the tests** (nothing to install; any PHP 8.0+):

```
php tests/run.php            # all unit tests
php tests/run.php Series     # only files whose name contains "Series"
```

`tests/unit/` covers the logic that needs no database: recurrence maths, ICS
parsing and expansion, series planning, cross-feed merge matching, the FC
Bayern hero rule, and repository sanity (every registered asset exists, every
required file exists). Warnings and deprecations fail a test.
`tests/integration/run.php` runs inside a real WordPress and is what CI uses to
check the REST feed, series behaviour against the database, the page and feed
builders, and that the PHP error log stays clean. It creates events: never run
it against the live site.

**CI** (`.github/workflows/ci.yml`) runs on every pull request and every push to
`main`: `php -l` and the unit tests on PHP 8.0–8.4, the integration tests in a
throwaway WordPress (`@wordpress/env`), and real HTTP requests for an event page,
the REST feed (ETag → 304) and `calendar.ics`. A pull request that changes
plugin code must also raise the `Version:` header in `gasf-events.php`.

**Releasing** is the same habit as before: bump `Version:` and merge to `main`.
When CI is green on `main` it tags `v<Version>` and publishes a GitHub release
with `gasf-events.zip`. Sites update from **releases**, not from `main`, so a
commit that fails its tests is never offered to them. A staging copy can follow
every push instead: `define( 'GASF_EVENTS_UPDATE_CHANNEL', 'main' );` in
`wp-config.php`. Events → Health shows the installed version and the latest release.

## Phases

See `docs/ARCHITECTURE.md` §11. P1 (this commit) = plugin scaffold, `gasf_event` model, settings, Event Details meta box, and the All Events list.

## License

GPL-2.0-or-later.
