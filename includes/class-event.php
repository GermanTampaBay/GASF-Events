<?php
/**
 * Event model — a typed wrapper around a `gasf_event` post.
 *
 * Everything downstream (display, schema, feeds, admin) reads events through
 * this object rather than poking raw meta, so date/time and fallback logic
 * live in exactly one place.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Event {

	/** @var \WP_Post */
	private $post;

	/** @var array{0:string,1:string}|null Memoised type() result. */
	private $type_cache = null;

	public function __construct( $post ) {
		$this->post = get_post( $post );
	}

	public static function get( $post ): ?Event {
		$p = get_post( $post );
		if ( ! $p || GASF_EVENTS_CPT !== $p->post_type ) {
			return null;
		}
		return new self( $p );
	}

	public function id(): int {
		return (int) $this->post->ID;
	}

	public function post(): \WP_Post {
		return $this->post;
	}

	public function title(): string {
		return html_entity_decode( get_the_title( $this->post ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Event "type": an emoji + a colour, used for the calendar icon and the soft
	 * colour behind each event. One lookup drives both so they never drift.
	 *
	 * Two layers, admin-defined first: the title-match rules from
	 * Events → Settings → "Calendar icons & colours" win over the built-in
	 * keyword map below, so the club can name an event's look without a code
	 * change (and can override a built-in guess it disagrees with — "Volleyball"
	 * picking up the 💃 from 'ball', say).
	 *
	 * Memoised: icon() and color() each call this, so a month grid asks twice
	 * per event, and the built-in map reads description() → do_shortcode().
	 *
	 * @return array{0:string,1:string} [ emoji, hex colour ]
	 */
	private function type(): array {
		if ( null !== $this->type_cache ) {
			return $this->type_cache;
		}
		$this->type_cache = $this->resolve_type();
		return $this->type_cache;
	}

	private function resolve_type(): array {
		$title = $this->title();
		foreach ( Settings::type_rules() as $rule ) {
			if ( false === mb_stripos( $title, $rule['match'] ) ) {
				continue;
			}
			// Both fields set is the common case, and returning here keeps the
			// built-in map (and its do_shortcode() pass) from running at all.
			if ( '' !== $rule['icon'] && '' !== $rule['color'] ) {
				return [ $rule['icon'], $rule['color'] ];
			}
			$base = $this->builtin_type();
			return [
				'' !== $rule['icon'] ? $rule['icon'] : $base[0],
				'' !== $rule['color'] ? $rule['color'] : $base[1],
			];
		}
		return $this->builtin_type();
	}

	/**
	 * The shipped keyword map — the fallback when no admin rule matches. Reads
	 * title AND description (a rule reads the title only). Order matters
	 * (specific first).
	 *
	 * @return array{0:string,1:string} [ emoji, hex colour ]
	 */
	private function builtin_type(): array {
		$t = mb_strtolower( $this->title() . ' ' . wp_strip_all_tags( $this->description() ) );
		$map = [
			'euchre'       => [ '🃏', '#b22222' ],
			'oktoberfest'  => [ '🍻', '#c9831f' ], 'feierabend' => [ '🍻', '#c9831f' ], 'stammtisch' => [ '🍻', '#c9831f' ],
			'biergarten'   => [ '🍺', '#d4a017' ], 'bier '      => [ '🍺', '#d4a017' ],
			'world cup'    => [ '⚽', '#1e5fad' ], 'watch party' => [ '⚽', '#1e5fad' ], 'bundesliga' => [ '⚽', '#1e5fad' ],
			'bayern'       => [ '⚽', '#1e5fad' ], 'soccer'      => [ '⚽', '#1e5fad' ], 'fußball'    => [ '⚽', '#1e5fad' ], ' v ' => [ '⚽', '#1e5fad' ],
			'dinner'       => [ '🍽️', '#6b6b6b' ], 'restaurant' => [ '🍽️', '#6b6b6b' ], 'schnitzel'  => [ '🍽️', '#6b6b6b' ],
			'crafting'     => [ '🧵', '#7a5cae' ], 'craft'       => [ '🧵', '#7a5cae' ], 'knit'       => [ '🧵', '#7a5cae' ],
			'cleanup'      => [ '🧹', '#3a7d44' ], 'clean up'    => [ '🧹', '#3a7d44' ], 'clean-up'   => [ '🧹', '#3a7d44' ],
			'broadway'     => [ '🎭', '#9c3587' ], 'theatre'     => [ '🎭', '#9c3587' ], 'theater'    => [ '🎭', '#9c3587' ], 'gala' => [ '🎭', '#9c3587' ],
			'concert'      => [ '🎵', '#4a4ab0' ], 'music'       => [ '🎵', '#4a4ab0' ], 'band'       => [ '🎵', '#4a4ab0' ],
			'dance'        => [ '💃', '#c0398b' ], 'ball'        => [ '💃', '#c0398b' ], 'tanz'       => [ '💃', '#c0398b' ],
			'krampus'      => [ '😈', '#7a1f1f' ],
			'weihnacht'    => [ '🎄', '#2f7d4f' ], 'christmas'   => [ '🎄', '#2f7d4f' ], 'advent'     => [ '🎄', '#2f7d4f' ],
			'wine'         => [ '🍷', '#8a2649' ], 'wein'        => [ '🍷', '#8a2649' ],
			'breakfast'    => [ '🍳', '#c47f1a' ], 'brunch'      => [ '🍳', '#c47f1a' ], 'pancake'    => [ '🍳', '#c47f1a' ],
			'picnic'       => [ '🧺', '#5f7d2f' ], 'bbq'         => [ '🍖', '#a14620' ], 'grill'      => [ '🍖', '#a14620' ],
			'meeting'      => [ '📋', '#455a78' ], 'verein'      => [ '📋', '#455a78' ], 'board'      => [ '📋', '#455a78' ],
			'deutsch'      => [ '🇩🇪', '#1a3d7c' ], 'german night' => [ '🇩🇪', '#1a3d7c' ],
		];
		foreach ( $map as $kw => $pair ) {
			if ( false !== mb_strpos( $t, $kw ) ) {
				return $pair;
			}
		}
		return [ '📅', Settings::DEFAULT_TYPE_COLOR ]; // default
	}

	/** Context emoji for the event (filterable). */
	public function icon(): string {
		return (string) apply_filters( 'gasf_events_icon', $this->type()[0], $this );
	}

	/** Type colour (hex) shown softly behind the event chip (filterable). */
	public function color(): string {
		return (string) apply_filters( 'gasf_events_color', $this->type()[1], $this );
	}

	public function description(): string {
		$content = (string) $this->post->post_content;
		// Only expand shortcodes for manually-authored events (e.g. [associate_fee]).
		// Feed-sourced content (Facebook/ICS) is remote input: kses at ingest strips
		// HTML but NOT [shortcode] syntax, so expanding it would let anyone who can
		// edit a synced Facebook event or ICS feed run registered shortcodes here.
		// strip_shortcodes() also keeps the single template's the_content pass from
		// re-expanding them.
		$source = (string) $this->meta( Meta::SOURCE );
		if ( '' === $source || 'manual' === $source ) {
			return do_shortcode( $content );
		}
		return strip_shortcodes( $content );
	}

	public function permalink(): string {
		return (string) get_permalink( $this->post );
	}

	private function meta( string $key ) {
		return get_post_meta( $this->post->ID, $key, true );
	}

	/* ---- Date / time -------------------------------------------------- */

	public function start(): ?\DateTimeImmutable {
		return Meta::to_datetime( (string) $this->meta( Meta::START ) );
	}

	public function end(): ?\DateTimeImmutable {
		return Meta::to_datetime( (string) $this->meta( Meta::END ) );
	}

	public function start_ts(): int {
		return (int) $this->meta( Meta::START_TS );
	}

	public function end_ts(): int {
		return (int) $this->meta( Meta::END_TS );
	}

	public function is_all_day(): bool {
		return (bool) $this->meta( Meta::ALL_DAY );
	}

	public function hide_time(): bool {
		return (bool) $this->meta( Meta::HIDE_TIME );
	}

	public function hide_end(): bool {
		return (bool) $this->meta( Meta::HIDE_END );
	}

	public function is_multiday(): bool {
		$s = $this->start();
		$e = $this->end();
		return $s && $e && $s->format( 'Y-m-d' ) !== $e->format( 'Y-m-d' );
	}

	public function is_past(): bool {
		$end = $this->end_ts() ?: $this->start_ts();
		return $end && $end < time();
	}

	/** ISO-8601 with timezone offset (for schema + iCal). */
	public function start_iso(): string {
		$s = $this->start();
		return $s ? $s->format( 'c' ) : '';
	}

	public function end_iso(): string {
		$e = $this->end();
		return $e ? $e->format( 'c' ) : '';
	}

	/* ---- Status ------------------------------------------------------- */

	public function status(): string {
		$s = (string) $this->meta( Meta::STATUS );
		return in_array( $s, Meta::STATUSES, true ) ? $s : '';
	}

	public function status_label(): string {
		switch ( $this->status() ) {
			case 'cancelled':   return __( 'Cancelled', 'gasf-events' );
			case 'postponed':   return __( 'Postponed', 'gasf-events' );
			case 'sold_out':    return __( 'Sold out', 'gasf-events' );
			case 'online_only': return __( 'Online only', 'gasf-events' );
			default:            return __( 'Scheduled', 'gasf-events' );
		}
	}

	/* ---- Provenance / series ------------------------------------------ */

	public function source(): string {
		$s = (string) $this->meta( Meta::SOURCE );
		return $s ?: 'manual';
	}

	/** True if this event came from a feed (facebook/ics/…), not a manual entry. */
	public function is_synced(): bool {
		$s = $this->source();
		return ( 'manual' !== $s ) || '' !== (string) $this->meta( Meta::SOURCE_UID ) || '' !== (string) $this->meta( Meta::FB_EVENT_ID );
	}

	public function series_id(): string {
		return (string) $this->meta( Meta::SERIES_ID );
	}

	public function is_recurring(): bool {
		return '' !== $this->series_id() && 'single' !== (string) $this->meta( Meta::SERIES_ROLE );
	}

	/* ---- Media -------------------------------------------------------- */

	/**
	 * Cover image URL, falling back to the site-wide default so an event is
	 * never image-less (Google schema requires `image`). See §4.3.
	 */
	public function cover_url( string $size = 'large' ): string {
		if ( has_post_thumbnail( $this->post ) ) {
			$url = get_the_post_thumbnail_url( $this->post, $size );
			if ( $url ) {
				return $url;
			}
		}
		return Settings::default_image_url( $size );
	}

	/**
	 * A RASTER image URL suitable for schema.org `image`, or '' if only the
	 * bundled SVG placeholder is available (Google rich results reject SVG —
	 * better to omit `image` than emit an invalid one). Admins should set a
	 * real default image in Settings.
	 */
	public function schema_image(): string {
		if ( has_post_thumbnail( $this->post ) ) {
			$url = get_the_post_thumbnail_url( $this->post, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		$id = Settings::default_image_id();
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	/* ---- Venue / organizer (settings + per-event override) ------------ */

	public function venue(): array {
		return $this->with_override( Meta::VENUE_OVERRIDE, Settings::venue() );
	}

	public function organizer(): array {
		return $this->with_override( Meta::ORGANIZER_OVERRIDE, Settings::organizer() );
	}

	/** A per-event override array merged over the defaults (only if it has a name). */
	private function with_override( string $meta_key, array $defaults ): array {
		$override = $this->meta( $meta_key );
		return is_array( $override ) && ! empty( $override['name'] )
			? wp_parse_args( $override, $defaults )
			: $defaults;
	}

	/* ---- Details ------------------------------------------------------ */

	public function status_reason(): string {
		return (string) $this->meta( Meta::STATUS_REASON );
	}

	public function more_info_url(): string {
		return (string) $this->meta( Meta::MORE_INFO_URL );
	}

	public function more_info_title(): string {
		$t = (string) $this->meta( Meta::MORE_INFO_TITLE );
		return $t ?: __( 'More info', 'gasf-events' );
	}

	public function cost(): float {
		return (float) $this->meta( Meta::COST );
	}

	public function online_link(): string {
		return (string) $this->meta( Meta::ONLINE_LINK );
	}

	/** Kiosk "how to watch" override: HDMI input ('' = no override). */
	public function tv_input(): string {
		return (string) $this->meta( Meta::TV_INPUT );
	}

	/** Kiosk "how to watch" override: DirecTV channel or app slug ('' = none). */
	public function tv_channel(): string {
		return (string) $this->meta( Meta::TV_CHANNEL );
	}

	/* ---- Promotion (road sign / home page) ----------------------------- */

	/** Offered to the road sign and home page. */
	public function is_featured(): bool {
		return (bool) $this->meta( Meta::FEATURED );
	}

	/** Ordering weight among featured events, 0–100, higher first. */
	public function priority(): int {
		return max( 0, min( 100, (int) $this->meta( Meta::PRIORITY ) ) );
	}

	/**
	 * The headline for a sign slide. Falls back to the full title so a consumer
	 * always has something to show; is_short_title_set() tells the two apart.
	 */
	public function short_title(): string {
		$s = (string) $this->meta( Meta::SHORT_TITLE );
		return '' !== $s ? $s : $this->title();
	}

	public function is_short_title_set(): bool {
		return '' !== (string) $this->meta( Meta::SHORT_TITLE );
	}

	/** The line under the headline on a sign slide ('' = none set). */
	public function sign_text(): string {
		return (string) $this->meta( Meta::SIGN_TEXT );
	}

	/* ---- Syndication / stats ------------------------------------------ */

	/** Per-destination publish state: [ '<dest>' => {id,url,status,synced_at,error} ]. */
	public function published(): array {
		$p = $this->meta( Meta::PUBLISHED );
		return is_array( $p ) ? $p : [];
	}

	public function published_to( string $dest ): array {
		$p = $this->published();
		return is_array( $p[ $dest ] ?? null ) ? $p[ $dest ] : [];
	}

	public function eventbrite_url(): string {
		return (string) ( $this->published_to( 'eventbrite' )['url'] ?? '' );
	}

	public function views(): int {
		return (int) $this->meta( Meta::VIEWS );
	}

	public function views_kiosk(): int {
		return (int) $this->meta( Meta::VIEWS_KIOSK );
	}
}
