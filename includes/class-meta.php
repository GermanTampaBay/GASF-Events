<?php
/**
 * Meta key registry + the canonical date/time derivation.
 *
 * The model stores human-local datetime strings (`_gasf_start`/`_gasf_end`, in
 * `Y-m-d H:i:s`, site timezone) as the source of truth, plus UTC unix
 * timestamps (`_gasf_start_ts`/`_gasf_end_ts`) derived from them for fast,
 * DST-correct range queries and sorting. See docs/ARCHITECTURE.md §4.2.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Meta {

	// Canonical date/time.
	const START      = '_gasf_start';        // string Y-m-d H:i:s (site-local)
	const END        = '_gasf_end';          // string Y-m-d H:i:s (site-local)
	const START_TS   = '_gasf_start_ts';     // int UTC unix
	const END_TS     = '_gasf_end_ts';       // int UTC unix
	const ALL_DAY    = '_gasf_all_day';      // bool
	const HIDE_TIME  = '_gasf_hide_time';    // bool
	const HIDE_END   = '_gasf_hide_end';     // bool

	// Status / details.
	const STATUS         = '_gasf_status';          // '' | cancelled | postponed | sold_out | online_only
	const STATUS_REASON  = '_gasf_status_reason';   // string
	const ONLINE_LINK    = '_gasf_online_link';     // url
	const MORE_INFO_URL   = '_gasf_more_info_url';  // url
	const MORE_INFO_TITLE = '_gasf_more_info_title';// string
	const COST           = '_gasf_cost';            // decimal (only when > 0)

	// How to watch (Bierstube kiosk game tiles — written by GASF-Utilities'
	// Game TV tab, read out through the REST feed's tv_input / tv_channel).
	const TV_INPUT   = '_gasf_tv_input';    // '' | directv | googletv | roku | fire
	const TV_CHANNEL = '_gasf_tv_channel';  // DirecTV channel number or app slug

	// Promotion (road sign, home page, kiosk) — v0.37.0. The sign builds a
	// slide per event; these let a maintainer say which events deserve one,
	// in what order, and what the few words on it should be.
	const FEATURED    = '_gasf_featured';     // bool — offered to the sign / home page
	const PRIORITY    = '_gasf_priority';     // int 0..100, higher first among featured
	const SHORT_TITLE = '_gasf_short_title';  // sign headline (≤ SHORT_TITLE_MAX chars)
	const SIGN_TEXT   = '_gasf_sign_text';    // the line under it (≤ SIGN_TEXT_MAX chars)
	const SHORT_TITLE_MAX = 40;
	const SIGN_TEXT_MAX   = 80;

	// Provenance & sync.
	const SOURCE       = '_gasf_source';        // manual | facebook | ics | eventbrite
	const SOURCE_UID   = '_gasf_source_uid';    // universal dedup key, namespaced "<source>|<uid>"
	const SOURCE_FEED  = '_gasf_source_feed';   // which feed config produced it
	const FB_EVENT_ID  = '_gasf_fb_event_id';   // FB mirror of the uid (kept for FB-specific UI)
	const FB_COVER_ID  = '_gasf_fb_cover_id';   // string
	const FB_MISSING   = '_gasf_fb_missing';    // int — consecutive misses (any synced source)
	const SYNC_LOCKED  = '_gasf_sync_locked';   // bool (the "pin")
	const FB_SNAPSHOT  = '_gasf_fb_snapshot';   // hash of last synced fields (auto-pin detection)

	// Venue / organizer per-event overrides (defaults live in Settings).
	const VENUE_OVERRIDE     = '_gasf_venue_override';     // array
	const ORGANIZER_OVERRIDE = '_gasf_organizer_override'; // array

	// Series (recurring).
	const SERIES_ID   = '_gasf_series_id';   // string
	const SERIES_ROLE = '_gasf_series_role'; // single | master | occurrence
	const REPEAT       = '_gasf_repeat';       // '' | weekly | biweekly | monthly (on the master)
	const REPEAT_UNTIL = '_gasf_repeat_until'; // Y-m-d (ends-on) — mutually exclusive with count
	const REPEAT_COUNT = '_gasf_repeat_count'; // int (number of occurrences incl. first)
	// v0.38.0 — per-occurrence series bookkeeping.
	const SERIES_SLOT       = '_gasf_series_slot';       // Y-m-d the RULE gave this occurrence, kept when it is moved by hand
	const SERIES_CUSTOMIZED = '_gasf_series_customized'; // bool — edited by hand; "apply to future" and orphan clean-up leave it alone

	/** Allowed repeat frequencies. */
	const REPEATS      = [ '', 'weekly', 'biweekly', 'monthly' ];
	/** Hard cap on generated occurrences per series (runaway guard). */
	const REPEAT_MAX   = 104;

	// Outbound publishing — generic, destination-agnostic (Eventbrite is the
	// first; more can register). Per-event state keyed by destination:
	// _gasf_published = [ '<dest>' => { id, url, status, synced_at, error } ].
	const PUBLISHED       = '_gasf_published';
	const PUBLISH_INTENT  = '_gasf_publish_intent'; // [ dest keys ] pending publish

	// Stats.
	const VIEWS       = '_gasf_views';        // int (total)
	const VIEWS_KIOSK = '_gasf_views_kiosk';  // int (kiosk taps subset)
	const VIEWS_DAILY = '_gasf_views_daily';  // [ Ymd => count ] (rolling)

	/** Allowed event statuses. */
	const STATUSES = [ '', 'cancelled', 'postponed', 'sold_out', 'online_only' ];

	const ON_ATTACHMENT_COVER_SHA1 = '_gasf_cover_sha1';

	/** Site timezone, memoized (request-stable; avoids re-parsing in hot loops). */
	public static function tz(): \DateTimeZone {
		static $tz = null;
		return $tz ??= wp_timezone();
	}

	/**
	 * Parse a site-local 'Y-m-d H:i:s' string into a DateTimeImmutable in the
	 * site timezone, or null. The single home for this construct.
	 */
	public static function to_datetime( string $local ): ?\DateTimeImmutable {
		$local = trim( $local );
		if ( '' === $local ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $local, self::tz() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Convert a site-local 'Y-m-d H:i:s' string to a UTC unix timestamp,
	 * DST-correctly, using the site timezone.
	 */
	public static function local_to_ts( string $local ): int {
		$dt = self::to_datetime( $local );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** Find one post id by a single meta key/value (0 if none). */
	public static function find_id_by( string $key, $value ): int {
		$ids = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => $key,
			'meta_value'       => $value,
			'suppress_filters' => true,
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Recompute and persist `_gasf_start_ts`/`_gasf_end_ts` from the stored
	 * local datetime strings. Call after any write to start/end.
	 */
	public static function recompute_timestamps( int $post_id ): void {
		$start = (string) get_post_meta( $post_id, self::START, true );
		$end   = (string) get_post_meta( $post_id, self::END, true );
		update_post_meta( $post_id, self::START_TS, self::local_to_ts( $start ) );
		update_post_meta( $post_id, self::END_TS, self::local_to_ts( $end ) );
	}

	/**
	 * Best-effort cross-process mutex via a MySQL advisory lock (connection-
	 * scoped: auto-released if the PHP worker dies mid-run). Returns true only
	 * if THIS request now holds the lock. $timeout=0 is non-blocking — a second
	 * concurrent caller gets false immediately rather than serialising.
	 */
	public static function acquire_lock( string $name, int $timeout = 0 ): bool {
		global $wpdb;
		$key = substr( 'gasfE:' . $name, 0, 60 );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, max( 0, $timeout ) ) );
	}

	public static function release_lock( string $name ): void {
		global $wpdb;
		$key = substr( 'gasfE:' . $name, 0, 60 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	/**
	 * Register meta with the CPT (sanitization + single). Kept protected
	 * (underscore-prefixed); the public REST feed is a dedicated endpoint (P7),
	 * not raw meta exposure.
	 */
	public static function register(): void {
		$string_keys = [
			self::START, self::END, self::STATUS, self::STATUS_REASON, self::ONLINE_LINK,
			self::MORE_INFO_URL, self::MORE_INFO_TITLE, self::SOURCE, self::FB_EVENT_ID,
			self::FB_COVER_ID, self::SERIES_ID, self::SERIES_ROLE,
			self::REPEAT, self::REPEAT_UNTIL, self::SHORT_TITLE, self::SIGN_TEXT, self::SERIES_SLOT,
		];
		foreach ( $string_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static fn( $a, $mk, $oid ) => current_user_can( 'edit_post', (int) $oid ),
			] );
		}

		$int_keys = [ self::START_TS, self::END_TS, self::FB_MISSING, self::VIEWS, self::VIEWS_KIOSK, self::REPEAT_COUNT, self::PRIORITY ];
		foreach ( $int_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn( $a, $mk, $oid ) => current_user_can( 'edit_post', (int) $oid ),
			] );
		}

		$bool_keys = [ self::ALL_DAY, self::HIDE_TIME, self::HIDE_END, self::SYNC_LOCKED, self::FEATURED, self::SERIES_CUSTOMIZED ];
		foreach ( $bool_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $v ) => (bool) $v,
				'auth_callback'     => static fn( $a, $mk, $oid ) => current_user_can( 'edit_post', (int) $oid ),
			] );
		}
	}
}
