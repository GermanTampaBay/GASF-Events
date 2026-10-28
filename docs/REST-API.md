# Public REST API

Public JSON feed of published events. No key and no auth on any route, and only
`publish`-status events are ever returned.

Everything under `/events` is **read-only `GET`**. There is one exception in the
namespace — `POST /view`, the view-counter beacon — documented at the bottom. It
writes a counter, never event content.

Base: `https://germantampabay.com/wp-json/gasf-events/v1`

---

## `GET /events`

Returns an array of events. With no parameters: **the next 100 upcoming events,
soonest first, with every field**.

```
https://germantampabay.com/wp-json/gasf-events/v1/events
```

### Parameters

| Param | Default | Notes |
|---|---|---|
| `limit` | `100` | 1–500. Values outside the range clamp rather than error. |
| `instance` | — | Pick a single event by position: `1` = the next one, `2` = the one after. See [Picking one event](#picking-one-event). |
| `event` | — | A named preset — `fcbayern` or `dinner`. See [Filtering by kind of event](#filtering-by-kind-of-event). |
| `contains` | — | Literal title text to match, for anything without a preset. |
| `order` | `asc` | `asc` = soonest first, `desc` = latest first. Case-insensitive; anything else is a 400. |
| `fields` | *(all)* | Which keys to return — see [Choosing fields](#choosing-fields). |
| `from` | *(now)* | Start of the window. `YYYY-MM-DD`, an ISO datetime, or a unix timestamp. A value that cannot be parsed is a `400`. |
| `to` | — | End of the window, same formats and the same `400`. |
| `updated_since` | — | Only events modified after this. Same formats. For incremental sync — but read [Syncing](#syncing) first, because on its own it cannot tell you an event went away. |
| `status` | *(all)* | Include list (`status=cancelled,postponed`) or exclude list (`status=-cancelled`), not both. Names: `scheduled`, `cancelled`, `postponed`, `sold_out`, `online_only`. Unknown is a `400`. |
| `featured` | *(all)* | `1` = only events ticked "Featured" in the editor; `0` = only the rest. |
| `source` | *(all)* | `facebook`, `ics` or `manual`. |
| `series_id` | *(all)* | All dates of one recurring series. |

Omitting both `from` and `to` gives upcoming events only (anything that has not
finished yet). Supplying either one switches to an explicit window, so
`?from=2026-01-01&to=2026-12-31` includes past events in that range.

### Response headers

- `X-GASF-Count` — number of events in the body.
- `Cache-Control: public, max-age=120` — set by the plugin, but **currently
  stripped before it reaches you.** Server config rewrites `Cache-Control` to
  `no-store` for everything under `/wp-json`, so nothing caches these responses:
  not browsers, not the CDN. Assume every request is a live database hit and
  poll accordingly. Tracked as a config fix, not a plugin one.
- `ETag` and `Last-Modified` (v0.36.0) — on `/events`, `/events/{x-y}` and
  `/events/{id}`. Send the `ETag` back as `If-None-Match` (or the date as
  `If-Modified-Since`) on your next poll and an unchanged result comes back as
  a bodiless **304**. The database query still runs; the transfer does not.
  For a kiosk or sign polling every minute this is the difference between a
  few hundred bytes and the whole payload each time.

```
GET /events?limit=5&fields=title,start,image
If-None-Match: "3c6e0b8a9c15224a8228b9a98ca1531d"

HTTP/1.1 304 Not Modified
ETag: "3c6e0b8a9c15224a8228b9a98ca1531d"
```

The `ETag` is a hash of the exact payload, so it changes with `fields`, `limit`
and every other parameter. Keep one per URL.

---

## Filtering by kind of event

`event=` takes a **named preset**, which resolves to the title text configured in
**Events → Settings → Event list filters** — the same value the
`[gasf_bayern_events]` and `[gasf_dinner_events]` shortcodes use. Retitle your
match events in Settings and the API follows automatically.

| Preset | Also accepts | Matches |
|---|---|---|
| `fcbayern` | `bayern`, `fcb`, `bayern munich`, `FC-Bayern` | Settings → *FC Bayern matches* (default `FC Bayern v`) |
| `dinner` | `dinner night` | Settings → *Dinner Nights* (default `Dinner`) |

Spelling is forgiving: case, spaces, hyphens and underscores are all ignored, so
`FCBayern`, `fc-bayern` and `FC Bayern` are the same preset.

```
/events?event=FCBayern
```

For anything without a preset, `contains=` takes literal title text — a
case-insensitive substring match, the same rule the shortcodes use:

```
/events?contains=Euchre
```

Use one or the other; sending both is a 400. An unrecognised `event=` is also a
400 that lists the valid presets and points you at `contains=`.

---

## Picking one event

`instance=` selects a **single** event by position in the result, counting from
**1**:

```
/events?event=FCBayern&instance=1     ← the next Bayern match
/events?event=FCBayern&instance=2     ← the one after that
```

It works with or without a filter — `?instance=2` alone is the second upcoming
event of any kind — and composes with `contains=` the same way.

- The response is still an **array**, holding one object. It does not become a
  bare object, so consumers never have to branch on the shape.
- Asking past the end returns `[]` with `X-GASF-Count: 0`, not a 404.
- `instance` is a position, not a count, so combining it with `limit` is a 400.
  Use one or the other.

> **Separate parameters with `&`, not `?`.** A URL has only one `?` — the rest
> are `&`. `?event=FCBayern&instance=2` is right; `?event=FCBayern?instance=2`
> is not, and returns a 400 that shows you the corrected URL.

---

## Picking a run of events

`/events/{x-y}` returns the events at **positions x to y inclusive**, counting
from 1, once every filter has been applied. It is `instance=` widened from a
single event to a span.

```
/events/1-4                  ← the next event and the three after it
/events/1-5?event=FCBayern   ← the next five Bayern matches
/events/2-2                  ← just the second one (same as instance=2)
```

The response is the **same array** `/events` returns — not a wrapper object — so
`fields` and everything else behave identically and no consumer has to branch on
the shape it got back.

| Rule | Behaviour |
|---|---|
| Positions | 1-based and inclusive: `1-4` is four events, `2-4` is three. |
| Cap | **20 events.** `1-50` is a `400`, not a quietly trimmed 20 — a short array is indistinguishable from "there are only 20". |
| Past the end | `[]` with `X-GASF-Count: 0`, not a `404`. |
| Partly past the end | `8-12` with only 10 matches returns the 3 that exist. |
| Backwards | `9-2` is a `400`. Write it low to high and use `order=desc` to read backwards. |
| With `limit` or `instance` | A `400`. The range already says which events to return. |

Every filter composes: `event`, `contains`, `from`, `to`, `order`, `fields` and
`updated_since` all mean exactly what they mean on `/events`.

### Response headers

- `X-GASF-Count` — how many events came back, which may be fewer than asked.
- `X-GASF-Range` — the range echoed back, e.g. `1-4`, so a short array can be
  told apart from a different window.

---

## Choosing fields

By default every event carries all 33 fields (17 through v0.36, 16 more
appended in v0.37.0). `fields` takes **either** a list to
include **or** a list of `-` prefixed names to exclude — not both in one request.

**Include only what you name:**

```
/events?limit=5&fields=title,start,image
```
```json
[
  { "title": "Dinner Night", "start": "2026-09-04T19:00:00-04:00", "image": "https://…/dinner.jpg" }
]
```

**Or drop just the heavy parts:**

```
/events?fields=-description,-venue
```

Two rules worth knowing:

- **You get exactly what you name.** Nothing is force-included — `fields=title`
  returns objects with only a `title` key, no `id`. Ask for `id` if you need it.
- **Typos are errors, not silence.** An unknown name returns `400` and lists the
  valid ones, so a misspelled field can't look like an empty field.

Key order in the response always follows the canonical order below, regardless of
the order you list them in.

### Available fields

| Field | Type | Notes |
|---|---|---|
| `id` | int | WordPress post ID. |
| `title` | string | |
| `slug` | string | |
| `url` | string | Permalink to the event page. |
| `start` | string | ISO 8601 with site offset. `""` if unset. |
| `end` | string | ISO 8601, `""` when the event has no end time. |
| `all_day` | bool | |
| `status` | string | `scheduled`, `cancelled`, `postponed`, `sold_out`, `online_only`. Never empty — an unset status reads as `scheduled`. |
| `image` | string | Cover URL (large). Falls back to the site default image, so never empty. |
| `description` | string | Plain text, tags stripped. |
| `organizer` | object | `{ name, url }` |
| `venue` | object | `{ name, street, city, state, zip, country, lat, lng }` |
| `series_id` | string | Groups occurrences of a recurring event; `""` when standalone. |
| `source` | string | `manual` (the default), `facebook`, or another feed identifier. |
| `modified` | string | ISO 8601 UTC — pair with `updated_since`. |
| `tv_input` | string | Kiosk "how to watch" override: `directv`, `googletv`, `roku`, `fire`, or `""` (no override — the kiosk uses its pinned-tile default). Set per event on the GASF-Utilities → Game TV tab. |
| `tv_channel` | string | Companion to `tv_input`: a DirecTV channel number (e.g. `242`) or an app slug (`fandango`, `paramount`, `youtube`). `""` when unset. |
| `hide_time` | bool | The site shows the date only. Honour it: `start`/`end` still carry the time, but the maintainer chose not to show it. |
| `hide_end` | bool | The site shows the start time only. |
| `status_reason` | string | The note next to a cancelled/postponed status ("rain"). `""` when none. |
| `online_link` | string | Where to send people for an `online_only` event. |
| `more_info_url` | string | Ticket or details link. `""` when none. |
| `more_info_title` | string | Its label. `""` when there is no link. |
| `cost` | number | Price in USD, `0` when free or unset. |
| `is_multiday` | bool | Start and end fall on different days. |
| `icon` | string | The emoji the calendar shows for this event. |
| `color` | string | Its hex colour (`#1e5fad`). Match the site's look without re-deriving it. |
| `has_cover` | bool | A real cover image is set. `false` means `image`/`images` carry the site default or the placeholder. |
| `images` | object | `{ thumbnail, medium, large, full }`, each `{ url, width, height }`. A 1080p sign wants `full`; a card grid wants `medium`. Width/height are `0` when only the SVG placeholder exists. |
| `featured` | bool | Ticked "Featured" under Road sign & promotion in the editor. |
| `priority` | int | 0–100, higher first among featured events. The feed does not sort by it; you do. |
| `short_title` | string | The sign headline, up to 40 characters. **Falls back to `title`** when none is set, so it is always usable. |
| `sign_text` | string | The line under the headline ("Sat 7 pm · $5 at the door"), up to 80 characters. `""` when none. |

`image` and `description` are the two expensive fields: `description` expands
shortcodes and `image` hits the attachment tables. Leaving them out of `fields`
skips that work entirely rather than computing and discarding it, so a lean
request is genuinely cheaper, not just smaller.

---

## `GET /events/{id}`

One event as a single object (not an array). Accepts `fields` with the same
rules. Returns `404` with `{"error":"not_found"}` if the ID is unknown or the
event is not published.

```
/events/1234?fields=title,start,image
```

---

## Syncing

`updated_since` answers *"what changed?"* but not *"what went away?"*. Both
list routes return published events only, so an event that gets unpublished
simply stops appearing — there is nothing in the response that tells a consumer
to drop the copy it is already showing.

That is not a theoretical case on this site. The feed importer drafts events by
itself when they vanish from their upstream source, so events disappear without
anyone touching them.

**If you cache events, poll `/events/changes` instead.**

### `GET /events/changes`

```
/events/changes?since=2026-08-01T12:00:00Z
```

```json
{
  "since":   "2026-08-01T12:00:00+00:00",
  "now":     "2026-08-29T18:30:00+00:00",
  "updated": [ { "id": 16275, "title": "Biergarten", "…": "…" } ],
  "removed": [ 16111, 16240 ],
  "truncated": false
}
```

| Param | Default | Notes |
|---|---|---|
| `since` | *(required)* | Same date formats as `from`. Missing or unparseable is a `400`. |
| `fields` | *(all)* | Applies to `updated` only, same rules as elsewhere. |
| `limit` | `500` | 1–500, applied to `updated` and `removed` separately. When either list fills up, `truncated` is `true` and `now` steps back to the last item's own modified time instead of the current time, so nothing past the cap is lost — poll again right away. |

- **`updated`** — full event objects, published, modified after `since`, ordered
  **oldest-modified first** so a consumer that stops halfway can resume from the
  last one it handled rather than starting over.
- **`removed`** — bare ids to drop. Covers unpublished, drafted, trashed and
  permanently deleted events alike. There is deliberately nothing else in there:
  an event you are being told to forget has no useful payload.
- **`now`** — the checkpoint for your next call. Store it and pass it back as
  `since`; do not use your own clock, which may not agree with the server's.
  It is taken before the lookup runs, and on a truncated page it is the
  modified time of the last item returned, so it can be earlier than the
  moment you received the response. That is deliberate.
- **`truncated`** — `true` when a list hit `limit`. Treat it as "there is more":
  call again immediately with the returned `now`.
- **Delivery is at-least-once.** `since` is inclusive, so an event modified in
  the exact second of a checkpoint can appear in two consecutive responses.
  Key your cache on `id` and a repeat costs nothing; the alternative was
  occasionally missing one, which cannot be recovered from.

Deletions are remembered for **90 days**. A consumer that goes quiet for longer
than that should do a full `/events` read rather than trusting a delta.

Responses are `Cache-Control: no-store` — a delta belongs to one caller's
checkpoint and is never shared.

---

## Errors

`400` responses use the standard WordPress REST error shape:

```json
{
  "code": "gasf_fields_unknown",
  "message": "Unknown field(s): titel. Available fields: id, title, slug, …",
  "data": { "status": 400, "unknown": ["titel"], "available": ["id", "title", "…"] }
}
```

| Code | Cause |
|---|---|
| `gasf_fields_unknown` | A name in `fields` isn't a real field. |
| `gasf_fields_mixed` | `fields` mixed plain and `-` prefixed names. |
| `gasf_order_invalid` | `order` was neither `asc` nor `desc`. |
| `gasf_event_unknown` | `event` wasn't a known preset. |
| `gasf_event_and_contains` | Both `event` and `contains` were sent. |
| `gasf_instance_with_limit` | Both `instance` and `limit` were sent. |
| `gasf_instance_invalid` | `instance` was zero, negative, or not a number. |
| `gasf_query_separator` | A `?` was used where a `&` belongs. |
| `gasf_date_invalid` | `from`, `to`, `updated_since` or `since` was not a readable date. |
| `gasf_range_invalid` | A `/events/{x-y}` range started below 1. |
| `gasf_range_backwards` | A range ended before it started, e.g. `9-2`. |
| `gasf_range_too_wide` | A range asked for more than 20 events. |
| `gasf_range_with_count` | A range was combined with `limit` or `instance`. |
| `gasf_status_unknown` | A name in `status` isn't a real status. |
| `gasf_status_mixed` | `status` mixed plain and `-` prefixed names. |
| `gasf_featured_invalid` | `featured` was neither `1` nor `0`. |

---

## Recipes

The next FC Bayern match:

```
/events?event=FCBayern&instance=1
```

The match after next, just what a card needs:

```
/events?event=FCBayern&instance=2&fields=title,start,image
```
```json
[
  {
    "title": "FC Bayern v Leipzig",
    "start": "2026-09-19T14:30:00-04:00",
    "image": "https://germantampabay.com/wp-content/uploads/bayern.jpg"
  }
]
```

The next five Bayern matches instead of just one:

```
/events?event=FCBayern&limit=5&fields=title,start,image
```

The same five as a positional range — handy when you want "the next N" without
thinking about `limit` semantics:

```
/events/1-5?event=FCBayern&fields=title,start,image
```

Matches two through four, skipping the one about to kick off:

```
/events/2-4?event=FCBayern&fields=title,start
```

The next Dinner Night:

```
/events?event=dinner&instance=1&fields=title,start,url
```

The next event, minimal payload — for a "coming up next" widget:

```
/events?limit=1&fields=title,start,url
```

Five upcoming events with art, for a card grid:

```
/events?limit=5&fields=id,title,start,url,image
```

Everything in one month, no bulky text:

```
/events?from=2026-10-01&to=2026-10-31&fields=-description
```

Everything modified since a checkpoint, ignoring removals — fine for a display
that re-reads the whole list anyway:

```
/events?updated_since=2026-08-01T12:00:00Z&limit=500
```

Incremental sync for anything that **caches** events — this is the one that also
tells you what to delete:

```
/events/changes?since=2026-08-01T12:00:00Z
```

This month's events, most recent first:

```
/events?from=2026-08-01&to=2026-08-31&order=desc
```

---

## `POST /view`

The view-counter beacon. The only non-`GET` route in the namespace, and the only
one that writes anything. It increments a per-event counter; it cannot create,
change or delete an event.

Counting happens here rather than server-side during page render because the HTML
is cached by the CDN, so a render is not a reliable signal that anyone saw it.

```
POST /wp-json/gasf-events/v1/view
Content-Type: application/json

{ "id": 16275, "ctx": "kiosk" }
```

| Param | Default | Notes |
|---|---|---|
| `id` | *(required)* | Event post ID. Unknown ids are a `404`. |
| `ctx` | `web` | `web` or `kiosk`. Anything else is treated as `web`. Kiosk views are counted separately as well as in the total. |

Always returns `200` with `{ "ok": true, "counted": <bool> }` when the event
exists. `counted: false` is normal and not an error — the request was accepted but
deliberately not tallied:

- the user agent looks like a bot or link-preview fetcher;
- the same client IP already counted this event within the last **6 hours**;
- the site-wide cap of **300 counted views per minute** was already reached.

A missing event returns `404` with `{ "ok": false }`.

The browser beacon is injected on single event pages as `window.GASF_VIEW`
(`{ id, url, ctx }`), and is deliberately not injected for logged-in editors so
your own visits do not inflate the numbers.

---

## Webhooks

Polling tells you about a cancellation on your next poll. A webhook tells you
now. Register a receiver URL under **Events → Webhooks** and the site POSTs to
it whenever the calendar changes: an event published, edited while published,
cancelled, unpublished, trashed, deleted, or drafted by the feed sync.

One request that changes many events (a feed run) is **one delivery** listing
them all. Deliveries are **at-least-once**: a failed POST (non-2xx, timeout,
unreachable) is retried after 2, 4, 8, 16 and 32 minutes, then dropped and
the failure shown on the Webhooks and Health screens. Keep polling
`/events/changes` as the safety net; the webhook is the fast path.

### Request

```
POST https://your-receiver/gasf-webhook
Content-Type: application/json
User-Agent: GASF-Events/0.37.0 (+https://germantampabay.com/)
X-GASF-Event: events.changed
X-GASF-Delivery: 7f3c…-uuid
X-GASF-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with the webhook's secret>
```

```json
{
  "event": "events.changed",
  "sent_at": "2026-11-07T18:30:12+00:00",
  "site": "https://germantampabay.com/",
  "changes": [
    { "action": "updated", "id": 16275, "title": "Euchre Night", "short_title": "Euchre Night",
      "start": "2026-11-12T19:00:00-05:00", "end": "2026-11-12T22:00:00-05:00", "all_day": false,
      "status": "scheduled", "featured": true, "priority": 40,
      "url": "https://germantampabay.com/events/euchre-night/", "modified": "2026-11-07T18:30:11+00:00" },
    { "action": "removed", "id": 16111, "post_status": "draft" }
  ],
  "changes_url": "https://germantampabay.com/wp-json/gasf-events/v1/events/changes",
  "events_url":  "https://germantampabay.com/wp-json/gasf-events/v1/events"
}
```

- **`updated`** carries a compact event (the `id, title, short_title, start,
  end, all_day, status, featured, priority, url, modified` fields, as on
  `/events`) so a receiver can act without a round trip. For anything else,
  fetch `/events/{id}`.
- **`removed`** is an id plus the status that took it off the feed (`draft`,
  `trash`, `private`, or `deleted`). Drop it.
- **`ping`** (`X-GASF-Event: ping`) is sent by the Test button, with one
  sample `updated` change (the next upcoming event) so the whole path can be
  checked. Treat it like any delivery, or ignore it by event name.

### Verifying the signature

Compute HMAC-SHA256 over the **raw request body bytes** with the secret shown
on the Webhooks screen, and compare in constant time:

```php
$expected = 'sha256=' . hash_hmac( 'sha256', file_get_contents( 'php://input' ), $secret );
if ( ! hash_equals( $expected, $_SERVER['HTTP_X_GASF_SIGNATURE'] ?? '' ) ) { http_response_code( 401 ); exit; }
```

```python
expected = "sha256=" + hmac.new(secret.encode(), request.data, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers.get("X-GASF-Signature", "")): abort(401)
```

Reply with any **2xx** within 8 seconds. Do the real work after replying, or
a slow receiver looks like a failed one and gets the same delivery again.
`X-GASF-Delivery` is stable across retries, so it is the key for de-duplication.

The receiver must be reachable from the web host over http(s). A device on the
club's LAN is not; put a small relay on a public host, or have the sign poll
`/events/changes`.

---

## Stability

The no-`fields` payload is a **frozen contract** (ARCHITECTURE §7) — the hallway
kiosk is being built against it. New fields may be appended to the end of the
list; existing keys will not be renamed, reordered, or removed. If you depend on
a specific shape, name it explicitly with `fields` and you are insulated from
additions entirely.
