<?php
/**
 * Public REST feed — the clean, owned contract the kiosk (and any consumer)
 * reads instead of scraping. `GET /wp-json/gasf-events/v1/events` with
 * from/to/limit/instance/event/contains/order/fields/updated_since. Read-only,
 * published events only, cache-friendly. `updated_since` enables incremental
 * kiosk sync. See docs/ARCHITECTURE.md §7 and docs/REST-API.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'gasf-events/v1';

	/**
	 * Hard-deleted event ids, keyed id => unix delete time, for the changes
	 * feed. A drafted or trashed post is still in the table and /events/changes
	 * finds it by status; a permanently deleted one leaves nothing behind, so
	 * its id has to be written down at the moment it goes or the feed can never
	 * tell a consumer to drop it.
	 */
	const TOMBSTONES = 'gasf_events_tombstones';

	/** How long a tombstone is kept. Bounds the option; far longer than any
	 * sane consumer's polling gap, so nothing is missed in practice. */
	const TOMBSTONE_TTL = 7776000; // 90 days

	/**
	 * Widest span /events/{x-y} will serve. Asking for more is a 400, not a
	 * quiet trim: handing back 20 of the 50 you asked for is indistinguishable
	 * from "there are only 20", which is the silent-wrong-answer failure this
	 * API rejects everywhere else.
	 */
	const RANGE_MAX = 20;

	/**
	 * Every field the payload can carry, in output order. Omitting `fields`
	 * returns all of them — that default IS the locked contract the kiosk is
	 * being built against (§7), so this list only ever grows at the end.
	 */
	const FIELDS = [
		'id', 'title', 'slug', 'url', 'start', 'end', 'all_day', 'status',
		'image', 'description', 'organizer', 'venue', 'series_id', 'source', 'modified',
		'tv_input', 'tv_channel',
	];

	/**
	 * Named `?event=` shortcuts. Each resolves to the title text configured in
	 * Events → Settings — the SAME value [gasf_bayern_events] and
	 * [gasf_dinner_events] read — so renaming how match events are titled stays
	 * one edit in one place and the API follows the site instead of drifting
	 * from it.
	 */
	const EVENT_PRESETS = [ 'fcbayern', 'dinner' ];

	/** Spellings that should land on a preset. Keys are already normalised. */
	const PRESET_ALIASES = [
		'bayern'         => 'fcbayern',
		'fcb'            => 'fcbayern',
		'fcbayernmunich' => 'fcbayern',
		'bayernmunich'   => 'fcbayern',
		'dinnernight'    => 'dinner',
		'dinnernights'   => 'dinner',
	];

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'record_tombstone' ], 10, 2 );
	}

	public function routes(): void {
		register_rest_route( self::NS, '/events', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'from'          => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'to'            => [ 'sanitize_callback' => 'sanitize_text_field' ],
				// No registered default for limit/instance: the callback has to be
				// able to tell "not supplied" from "supplied as 100", because
				// instance and limit are mutually exclusive and a default would
				// make every instance request look like it also asked for a limit.
				'limit'         => [ 'sanitize_callback' => 'absint' ],
				// Deliberately NOT absint: it would fold instance=-3 into 3 and
				// silently serve the third match. The sign has to survive to the
				// callback so an out-of-range value can be told apart from a valid one.
				'instance'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'event'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'contains'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'order'         => [ 'default' => 'asc', 'sanitize_callback' => 'sanitize_text_field' ],
				'fields'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'updated_since' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'events' ],
		] );
		register_rest_route( self::NS, '/events/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'fields' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'single' ],
		] );
		// Registered alongside /events/(\d+) rather than as a parameter on it:
		// "changes" cannot match \d+, so the two never collide, and keeping the
		// delta shape off /events leaves that frozen payload (§7) untouched.
		register_rest_route( self::NS, '/events/changes', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'since'  => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
				'fields' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'limit'  => [ 'sanitize_callback' => 'absint' ],
			],
			'callback'            => [ $this, 'changes' ],
		] );
		// {x-y} is an inclusive 1-based position span, the same counting that
		// instance= uses: /events/1-4 is the next event and the three after it.
		// The dash keeps it clear of /events/{id}, whose pattern is digits only.
		register_rest_route( self::NS, '/events/(?P<range>\d+-\d+)', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'from'          => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'to'            => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'event'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'contains'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'order'         => [ 'default' => 'asc', 'sanitize_callback' => 'sanitize_text_field' ],
				'fields'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'updated_since' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'range' ],
		] );
	}

	public function events( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$order = self::resolve_order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$contains = $this->resolve_contains( $req );
		if ( is_wp_error( $contains ) ) {
			return $contains;
		}

		// instance is 1-based and picks ONE event: instance=1 is the next match,
		// instance=2 the one after it. It is an offset, not a count, so pairing it
		// with limit is rejected rather than silently letting one win.
		$has_limit    = null !== $req['limit'] && '' !== (string) $req['limit'];
		$has_instance = null !== $req['instance'] && '' !== (string) $req['instance'];
		$offset       = 0;
		if ( $has_instance ) {
			if ( $has_limit ) {
				return new \WP_Error(
					'gasf_instance_with_limit',
					__( 'instance returns a single event, so it cannot be combined with limit. Drop limit, or use limit on its own.', 'gasf-events' ),
					[ 'status' => 400 ]
				);
			}
			$instance = (int) $req['instance'];
			if ( $instance < 1 ) {
				return new \WP_Error(
					'gasf_instance_invalid',
					__( 'instance counts from 1, where instance=1 is the next matching event.', 'gasf-events' ),
					[ 'status' => 400 ]
				);
			}
			$offset = $instance - 1;
			$limit  = 1;
		} else {
			$limit = $has_limit ? min( 500, max( 1, (int) $req['limit'] ) ) : 100;
		}

		return $this->collect( $req, $fields, $order, $contains, $offset, $limit );
	}

	/**
	 * A positional slice: /events/{x-y} returns the events at positions x to y
	 * inclusive, counting from 1, after every filter has been applied.
	 *
	 *   /events/1-4                  the next event and the three after it
	 *   /events/1-5?event=FCBayern   the next five Bayern matches
	 *
	 * It is instance= widened from one event to a span, and inherits the same
	 * answers: positions are 1-based, and asking past the end is an empty array
	 * with X-GASF-Count: 0 rather than a 404 — "no fifth match yet" is an empty
	 * result, not a missing resource.
	 */
	public function range( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$order = self::resolve_order( $req );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$contains = $this->resolve_contains( $req );
		if ( is_wp_error( $contains ) ) {
			return $contains;
		}

		// limit and instance both describe how many events to return, which the
		// range has already said. Quietly ignoring one would serve a payload the
		// caller did not ask for — same rule as instance+limit.
		foreach ( [ 'limit', 'instance' ] as $conflict ) {
			if ( null !== $req[ $conflict ] && '' !== (string) $req[ $conflict ] ) {
				return new \WP_Error(
					'gasf_range_with_count',
					sprintf(
						/* translators: %s: the conflicting parameter name */
						__( 'A /events/{x-y} range already says which events to return, so it cannot be combined with %s.', 'gasf-events' ),
						$conflict
					),
					[ 'status' => 400, 'param' => $conflict ]
				);
			}
		}

		// The route pattern guarantees digits-dash-digits, so this cannot fail.
		[ $first, $last ] = array_map( 'intval', explode( '-', (string) $req['range'], 2 ) );

		if ( $first < 1 ) {
			return new \WP_Error(
				'gasf_range_invalid',
				__( 'A range counts from 1, so /events/1-4 is the next four events.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}
		if ( $last < $first ) {
			return new \WP_Error(
				'gasf_range_backwards',
				sprintf(
					/* translators: 1: first position, 2: last position */
					__( 'The range %1$d-%2$d ends before it starts. Write it low to high, and use order=desc to read backwards.', 'gasf-events' ),
					$first,
					$last
				),
				[ 'status' => 400 ]
			);
		}

		$span = $last - $first + 1;
		if ( $span > self::RANGE_MAX ) {
			return new \WP_Error(
				'gasf_range_too_wide',
				sprintf(
					/* translators: 1: number of events asked for, 2: the cap */
					__( 'That range asks for %1$d events; one range can return at most %2$d. Narrow it, or use limit= for a longer run.', 'gasf-events' ),
					$span,
					self::RANGE_MAX
				),
				[ 'status' => 400, 'requested' => $span, 'max' => self::RANGE_MAX ]
			);
		}

		$resp = $this->collect( $req, $fields, $order, $contains, $first - 1, $span );
		if ( ! is_wp_error( $resp ) ) {
			// Echoed back so a caller holding a short array can tell "you reached the
			// end of the calendar" from "you asked for a different window".
			$resp->header( 'X-GASF-Range', $first . '-' . $last );
		}
		return $resp;
	}

	/**
	 * asc/desc, case-insensitive. A typo is a 400 rather than a silent fallback:
	 * "order=descending" quietly returning ascending is a nasty surprise.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve_order( \WP_REST_Request $req ) {
		$order = strtolower( trim( (string) $req['order'] ) ) ?: 'asc';
		if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
			return new \WP_Error(
				'gasf_order_invalid',
				__( 'The order parameter must be asc or desc.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}
		return $order;
	}

	/**
	 * Window, query and payload shared by every list route, so /events and
	 * /events/{x-y} cannot drift apart: whatever from/to/updated_since/order/
	 * fields/event/contains mean on one, they mean on the other. The two routes
	 * differ only in how they arrive at $offset and $limit.
	 *
	 * @param string[]|null $fields Field names to emit, or null for all of them.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function collect( \WP_REST_Request $req, ?array $fields, string $order, string $contains, int $offset, int $limit ) {
		$from = trim( (string) $req['from'] );
		$to   = trim( (string) $req['to'] );

		$meta_query = [ 'relation' => 'AND' ];
		if ( '' !== $from || '' !== $to ) {
			if ( '' !== $from ) {
				$from_ts = self::parse_date( $from, 'from' );
				if ( is_wp_error( $from_ts ) ) {
					return $from_ts;
				}
				$meta_query[] = [ 'key' => Meta::END_TS, 'value' => $from_ts, 'type' => 'NUMERIC', 'compare' => '>=' ];
			}
			if ( '' !== $to ) {
				$to_ts = self::parse_date( $to, 'to' );
				if ( is_wp_error( $to_ts ) ) {
					return $to_ts;
				}
				$meta_query[] = [ 'key' => Meta::START_TS, 'value' => $to_ts, 'type' => 'NUMERIC', 'compare' => '<=' ];
			}
		} else {
			// Default: upcoming.
			$meta_query[] = [ 'key' => Meta::END_TS, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '>=' ];
		}

		$args = [
			'post_type'           => GASF_EVENTS_CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'meta_key'            => Meta::START_TS,
			'orderby'             => 'meta_value_num',
			// asc = soonest first (so limit=1 is "the next event"); desc = most
			// recent first, which is what you want when reading past events.
			'order'               => strtoupper( $order ),
			'meta_query'          => $meta_query,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];

		if ( $offset > 0 ) {
			$args['offset'] = $offset;
		}

		$since_raw = trim( (string) $req['updated_since'] );
		if ( '' !== $since_raw ) {
			$since_ts = self::parse_date( $since_raw, 'updated_since' );
			if ( is_wp_error( $since_ts ) ) {
				return $since_ts;
			}
			$args['date_query'] = [ [ 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $since_ts ), 'inclusive' => false ] ];
		}

		$q    = $this->query( $args, $contains );
		$data = array_map( fn( $p ) => $this->to_json( new Event( $p ), $fields ), $q->posts );

		$resp = new \WP_REST_Response( $data );
		$resp->header( 'X-GASF-Count', (string) count( $data ) );
		$resp->header( 'Cache-Control', 'public, max-age=120' );
		return $resp;
	}

	public function single( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$e = Event::get( (int) $req['id'] );
		if ( ! $e || 'publish' !== get_post_status( $e->id() ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		return new \WP_REST_Response( $this->to_json( $e, $fields ) );
	}

	/**
	 * Delta feed for incremental consumers: what changed since a checkpoint.
	 *
	 * /events answers "what is published right now", which is the wrong shape to
	 * sync against: an unpublished event simply stops appearing, and a consumer
	 * has no way to learn it should drop the copy it is still showing. That is
	 * not hypothetical here — Event_Ingest::prune_missing() drafts events on its
	 * own, so the system manufactures disappearances nobody typed.
	 *
	 * Returns { since, now, updated[], removed[] }. Poll with the previous
	 * response's `now` as the next `since`. `updated` is ordered oldest-modified
	 * first, so a consumer that stops halfway can resume rather than restart.
	 */
	public function changes( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$since = self::parse_date( (string) $req['since'], 'since' );
		if ( is_wp_error( $since ) ) {
			return $since;
		}

		$has_limit = null !== $req['limit'] && '' !== (string) $req['limit'];
		$limit     = $has_limit ? min( 500, max( 1, (int) $req['limit'] ) ) : 500;

		// The checkpoint is taken BEFORE the queries run. Taken afterwards, an
		// event modified while they ran sat between the two and was never
		// reported: the consumer's next `since` had already moved past it.
		$now = time();

		$common = [
			'post_type'           => GASF_EVENTS_CPT,
			'posts_per_page'      => $limit,
			'orderby'             => 'modified',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			// Inclusive, because a truncated page hands back the last item's own
			// modified time as the checkpoint (below): anything else modified in
			// that same second must be picked up by the next poll, even at the
			// cost of repeating the item itself. Delivery is at-least-once;
			// consumers key on id, so a repeat is harmless and a gap is not.
			'date_query'          => [ [
				'column'    => 'post_modified_gmt',
				'after'     => gmdate( 'Y-m-d H:i:s', $since ),
				'inclusive' => true,
			] ],
		];

		$updated = new \WP_Query( $common + [ 'post_status' => 'publish' ] );

		// Anything no longer published is "gone" to this feed's consumers: /events
		// will not return it again until it is republished, so a cached copy has to
		// be dropped either way. Reported as ids, not objects — there is nothing
		// useful left to say about an event that should disappear.
		$gone = new \WP_Query( $common + [
			'post_status' => [ 'draft', 'pending', 'private', 'future', 'trash' ],
			'fields'      => 'ids',
		] );

		// A full page may have more behind it. The old response said nothing and
		// still advanced `now`, so a feed run or migration that touched more than
		// $limit events lost everything past the cap for good. When either list
		// is full, the checkpoint becomes the oldest last-item time instead, so
		// the next poll resumes there; `truncated` tells the consumer to poll
		// again straight away rather than wait for its usual interval.
		$truncated = false;
		foreach ( [ $updated->posts, $gone->posts ] as $list ) {
			if ( count( $list ) >= $limit ) {
				$truncated = true;
				$last_ts   = (int) get_post_modified_time( 'U', true, end( $list ) );
				// If the whole page sits inside the checkpoint's own second, handing
				// that second back again would return the same page forever. Step
				// past it instead. More than $limit events modified within one
				// second is now the only way to lose one, and limit goes up to 500.
				$now = min( $now, $last_ts > $since ? $last_ts : $since + 1 );
			}
		}

		$removed = array_map( 'intval', $gone->posts );
		foreach ( (array) get_option( self::TOMBSTONES, [] ) as $id => $ts ) {
			if ( (int) $ts >= $since ) {
				$removed[] = (int) $id;
			}
		}
		$removed = array_values( array_unique( $removed ) );

		$resp = new \WP_REST_Response( [
			'since'     => gmdate( 'c', $since ),
			'now'       => gmdate( 'c', $now ),
			'updated'   => array_map( fn( $p ) => $this->to_json( new Event( $p ), $fields ), $updated->posts ),
			'removed'   => $removed,
			'truncated' => $truncated,
		] );
		$resp->header( 'X-GASF-Count', (string) count( $updated->posts ) );
		$resp->header( 'X-GASF-Removed', (string) count( $removed ) );
		// A delta belongs to one caller's checkpoint; it is never shared.
		$resp->header( 'Cache-Control', 'no-store' );
		return $resp;
	}

	/**
	 * Remember a permanently deleted event long enough for the changes feed to
	 * report it. Draft and trash need no hook — the row survives and changes()
	 * finds it by status — but a hard delete leaves nothing to find.
	 *
	 * @param int           $post_id Post being deleted.
	 * @param \WP_Post|null $post    The post object, when WordPress passes one.
	 */
	public static function record_tombstone( int $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( GASF_EVENTS_CPT !== $type ) {
			return;
		}
		$now    = time();
		$stones = (array) get_option( self::TOMBSTONES, [] );
		// Pruned on write because this is the only thing that grows the option, so
		// it is also the only place that has to bound it.
		$stones = array_filter( $stones, static fn( $ts ) => ( $now - (int) $ts ) < self::TOMBSTONE_TTL );
		$stones[ (string) $post_id ] = $now;
		update_option( self::TOMBSTONES, $stones, false );
	}

	/**
	 * A caller-supplied date as a timestamp, or a 400 naming what was wrong.
	 *
	 * Quietly ignoring an unreadable date is the one thing this API refused to do
	 * everywhere else — order=descending and a misspelt field are both errors —
	 * and dates were the gap: ?from=garbage used to return the whole archive with
	 * a 200, which reads as a successful answer to a question nobody asked.
	 *
	 * @return int|\WP_Error
	 */
	private static function parse_date( string $raw, string $param ) {
		$ts = self::ts( $raw );
		if ( null === $ts ) {
			return new \WP_Error(
				'gasf_date_invalid',
				sprintf(
					/* translators: 1: parameter name, 2: the value that could not be parsed */
					__( 'The %1$s parameter is not a date I can read: "%2$s". Use YYYY-MM-DD, an ISO 8601 datetime, or a unix timestamp.', 'gasf-events' ),
					$param,
					$raw
				),
				[ 'status' => 400, 'param' => $param, 'value' => $raw ]
			);
		}
		return $ts;
	}

	/**
	 * Normalized event JSON — the stable contract.
	 *
	 * @param string[]|null $fields Field names to emit, or null for all of them.
	 */
	public function to_json( Event $e, ?array $fields = null ): array {
		$out = [];
		foreach ( self::FIELDS as $name ) {
			if ( null === $fields || in_array( $name, $fields, true ) ) {
				$out[ $name ] = $this->field( $e, $name );
			}
		}
		return $out;
	}

	/**
	 * One field's value. Built per field rather than all at once so an
	 * unrequested field costs nothing — `description` runs do_shortcode() and
	 * `image` hits the attachment tables, which is most of the work in a
	 * 100-event response that a title-and-date consumer never wanted.
	 *
	 * @return mixed
	 */
	private function field( Event $e, string $name ) {
		switch ( $name ) {
			case 'id':
				return $e->id();
			case 'title':
				return $e->title();
			case 'slug':
				return $e->post()->post_name;
			case 'url':
				return $e->permalink();
			case 'start':
				return $e->start_iso();
			case 'end':
				return $e->end_iso();
			case 'all_day':
				return $e->is_all_day();
			case 'status':
				return $e->status() ?: 'scheduled';
			case 'image':
				return $e->cover_url( 'large' );
			case 'description':
				return wp_strip_all_tags( $e->description() );
			// Explicit whitelist — keep internal flags (hide_map, etc.) out of the
			// public contract and make future admin-only fields additive-safe.
			case 'organizer':
				$o = $e->organizer();
				return [ 'name' => $o['name'] ?? '', 'url' => $o['url'] ?? '' ];
			case 'venue':
				$v = $e->venue();
				return [
					'name'    => $v['name'] ?? '',
					'street'  => $v['street'] ?? '',
					'city'    => $v['city'] ?? '',
					'state'   => $v['state'] ?? '',
					'zip'     => $v['zip'] ?? '',
					'country' => $v['country'] ?? '',
					'lat'     => $v['lat'] ?? '',
					'lng'     => $v['lng'] ?? '',
				];
			case 'series_id':
				return $e->series_id();
			case 'source':
				return $e->source();
			case 'modified':
				return get_post_modified_time( 'c', true, $e->post() );
			// "How to watch" override for the Bierstube kiosk game tiles —
			// written per event by GASF-Utilities' Game TV tab. '' = no
			// override (the kiosk falls back to its pinned-tile default).
			case 'tv_input':
				return $e->tv_input();
			case 'tv_channel':
				return $e->tv_channel();
		}
		return null; // unreachable: resolve_fields() rejects unknown names
	}

	/**
	 * Turn `?fields=` into the list of keys to emit.
	 *
	 * Two forms, because "give me only these two" and "give me everything but
	 * the huge one" are both common and each is clumsy to express in the other:
	 *
	 *   fields=title,start        → exactly those keys
	 *   fields=-description,-venue → everything except those
	 *
	 * Mixing the forms is rejected rather than guessed at — `title,-image`
	 * has two equally plausible readings, and picking one silently would send
	 * a consumer the wrong payload. Unknown names are rejected too, so a typo
	 * is a 400 and not a mysteriously absent field.
	 *
	 * Whatever you name is exactly what you get; nothing is force-included.
	 *
	 * @return string[]|null|\WP_Error Null means "everything" (the default).
	 */
	private function resolve_fields( string $raw ) {
		$names = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
		if ( ! $names ) {
			return null;
		}

		$negated = array_filter( $names, static fn( $n ) => str_starts_with( $n, '-' ) );
		if ( $negated && count( $negated ) !== count( $names ) ) {
			return new \WP_Error(
				'gasf_fields_mixed',
				__( 'The fields parameter takes either a list to include or a list of -excluded names, not both.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}

		$clean   = array_map( static fn( $n ) => ltrim( $n, '-' ), $names );
		$unknown = array_values( array_diff( $clean, self::FIELDS ) );
		if ( $unknown ) {
			return new \WP_Error(
				'gasf_fields_unknown',
				sprintf(
					/* translators: 1: unknown field names, 2: the valid field names */
					__( 'Unknown field(s): %1$s. Available fields: %2$s.', 'gasf-events' ),
					implode( ', ', $unknown ),
					implode( ', ', self::FIELDS )
				),
				[ 'status' => 400, 'unknown' => $unknown, 'available' => self::FIELDS ]
			);
		}

		return $negated ? array_values( array_diff( self::FIELDS, $clean ) ) : $clean;
	}

	/**
	 * Run the query, optionally narrowed to titles containing $contains.
	 *
	 * The title match is done in SQL rather than by fetching a page of events
	 * and filtering in PHP the way the shortcodes do. The shortcodes can get
	 * away with it because they read a fixed 200 and render at most a handful;
	 * here, filtering after the fact would make `instance` wrong the moment the
	 * Nth match sat past the fetch window, and would break `limit` besides.
	 *
	 * MySQL's default collation is case-insensitive, so LIKE matches the same
	 * events the shortcodes' mb_strtolower() comparison does.
	 */
	private function query( array $args, string $contains ): \WP_Query {
		if ( '' === $contains ) {
			return new \WP_Query( $args );
		}
		// Passed as a query var (not just captured) so the filter only touches
		// THIS query, even if something else runs one while it is attached.
		$args['gasf_title_contains'] = $contains;
		$cb = static function ( $where, $wp_q ) {
			global $wpdb;
			$needle = (string) $wp_q->get( 'gasf_title_contains' );
			if ( '' === $needle ) {
				return $where;
			}
			return $where . $wpdb->prepare(
				" AND {$wpdb->posts}.post_title LIKE %s",
				'%' . $wpdb->esc_like( $needle ) . '%'
			);
		};
		add_filter( 'posts_where', $cb, 10, 2 );
		try {
			return new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $cb, 10 );
		}
	}

	/**
	 * Work out the title text to filter on from `?event=` (a named preset) or
	 * `?contains=` (literal text). Empty string means "no title filter".
	 *
	 * @return string|\WP_Error
	 */
	private function resolve_contains( \WP_REST_Request $req ) {
		$event    = trim( (string) $req['event'] );
		$literal  = trim( (string) $req['contains'] );

		if ( '' !== $event && '' !== $literal ) {
			return new \WP_Error(
				'gasf_event_and_contains',
				__( 'Use either event (a named preset) or contains (literal title text), not both.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}
		if ( '' !== $literal ) {
			return $literal;
		}
		if ( '' === $event ) {
			return '';
		}

		// `?event=FCBayern?instance=2` — a second "?" instead of "&". PHP hands us
		// the whole tail as the event name, which would otherwise surface as a
		// baffling "unknown preset: FCBayern?instance=2".
		if ( str_contains( $event, '?' ) ) {
			return new \WP_Error(
				'gasf_query_separator',
				sprintf(
					/* translators: %s: the corrected query string */
					__( 'Query parameters are separated by "&", not "?". Did you mean ?event=%s', 'gasf-events' ),
					str_replace( '?', '&', $event )
				),
				[ 'status' => 400 ]
			);
		}

		$key    = self::normalize_preset( $event );
		$filter = self::preset_filter( $key );
		if ( null === $filter ) {
			return new \WP_Error(
				'gasf_event_unknown',
				sprintf(
					/* translators: 1: the supplied name, 2: the valid preset names */
					__( 'Unknown event preset "%1$s". Available: %2$s. For any other title text use contains= instead.', 'gasf-events' ),
					$event,
					implode( ', ', self::EVENT_PRESETS )
				),
				[ 'status' => 400, 'available' => self::EVENT_PRESETS ]
			);
		}
		return $filter;
	}

	/**
	 * Fold a supplied preset name down to its canonical key. Everything that is
	 * not a letter or a digit is dropped, so "FC Bayern", "fc-bayern", "FCBayern"
	 * and "fc_bayern" are one name — the caller should not have to guess the
	 * punctuation we happened to pick.
	 */
	private static function normalize_preset( string $raw ): string {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ) );
		return self::PRESET_ALIASES[ $key ] ?? $key;
	}

	/** The configured title text for a preset key, or null if there is no such preset. */
	private static function preset_filter( string $key ): ?string {
		switch ( $key ) {
			case 'fcbayern':
				return Settings::bayern_filter();
			case 'dinner':
				return Settings::dinner_filter();
		}
		return null;
	}

	/**
	 * Parse a date/ISO/epoch string to a unix timestamp (site-tz aware for dates).
	 *
	 * Returns NULL when the value cannot be parsed, which is the whole point:
	 * returning 0 for both "empty" and "nonsense" is what let ?from=garbage fall
	 * through to END_TS >= 0 and quietly serve the entire archive as though it
	 * were the window the caller asked for. Callers reject null instead.
	 */
	private static function ts( string $v ): ?int {
		$v = trim( $v );
		if ( '' === $v ) {
			return null;
		}
		if ( ctype_digit( $v ) ) {
			return (int) $v;
		}
		// Plain Y-m-d → treat as site-local midnight.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return Meta::local_to_ts( $v . ' 00:00:00' );
		}
		$t = strtotime( $v );
		return false === $t ? null : $t;
	}
}
