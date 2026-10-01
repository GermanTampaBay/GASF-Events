<?php
/**
 * Hours_Sync — rebuilds the Business Profile's special hours from the event
 * calendar, so the hall's published hours stop going stale.
 *
 * THE MODEL. `regularHours` is the always-true baseline (Saturday 18:00–22:00)
 * and is never written by this plugin. `specialHours` is a per-date override
 * generated from `gasf_event`, and it is what makes Oktoberfest Fridays appear
 * and then disappear on their own.
 *
 * A day's published hours are the UNION of its regular hours and its events —
 * not a replacement. Saturday regular 18–22 plus an Oktoberfest event 12–22 is
 * 12–22; a Saturday with a 19:00–23:00 concert is 18–23. "We are always open
 * 6–10 on Saturday" stays true even when an event is shorter than that.
 *
 * A day whose union equals its regular hours emits NOTHING. The published set
 * therefore only ever contains genuine exceptions, which keeps it small and
 * means a quiet week writes no override at all.
 *
 * FULL REPLACE. Google swaps the entire specialHourPeriods list on every PATCH;
 * there is no append. So each run recomputes the whole rolling window from
 * scratch. That is also why it is self-healing: a failed or partial run is
 * corrected by the next one and there is no drift state to repair.
 *
 * See docs/GBP-HOURS-SYNC.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Hours_Sync {

	const OPT_ENABLE   = 'gasf_events_gbp_enable';      // master gate, ships OFF
	const OPT_LOCATION = 'gasf_events_gbp_location';    // "locations/1687..."
	const OPT_WINDOW   = 'gasf_events_gbp_window';      // days ahead
	const OPT_BUFFER   = 'gasf_events_gbp_buffer';      // minutes before start
	const OPT_HASH     = 'gasf_events_gbp_hash';        // last pushed payload
	const OPT_LAST     = 'gasf_events_gbp_last';        // last run stats
	const REG_CACHE    = 'gasf_events_gbp_regular';     // regularHours, 12h

	const CRON_PUSH  = 'gasf_events_gbp_push';
	const CRON_DAILY = 'gasf_events_gbp_daily';

	/**
	 * Debounce before a change-triggered push. A feed run writes dozens of
	 * events in one request; without this, each wp_insert_post would queue its
	 * own PATCH and the first few would publish half-built windows.
	 */
	const DEBOUNCE = 120;

	/** Google's own ceiling: a special-hour period must span under 24 hours. */
	const MAX_SPAN = 1439;

	public function register_hooks(): void {
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ __CLASS__, 'on_save' ], 20, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 20, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ], 20, 2 );
		add_action( self::CRON_PUSH, [ __CLASS__, 'cron_run' ] );
		add_action( self::CRON_DAILY, [ __CLASS__, 'cron_run' ] );
		add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
	}

	/* ---- settings ----------------------------------------------------- */

	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLE, false );
	}
	public static function location(): string {
		return trim( (string) get_option( self::OPT_LOCATION, '' ) );
	}
	public static function window_days(): int {
		return max( 1, min( 365, (int) get_option( self::OPT_WINDOW, 90 ) ) );
	}
	/** Default 0: the hours hand-copied into the listing used exact event times. */
	public static function buffer_min(): int {
		return max( 0, min( 240, (int) get_option( self::OPT_BUFFER, 0 ) ) );
	}

	public static function ready(): bool {
		return self::enabled() && '' !== self::location() && Google_Business_Profile::available();
	}

	/* ---- triggers ----------------------------------------------------- */

	public static function on_save( $post_id, $post, $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		self::schedule_push();
	}

	/** Publish/unpublish/trash all change which days are open. */
	public static function on_transition( $new, $old, $post ): void {
		if ( ! $post instanceof \WP_Post || GASF_EVENTS_CPT !== $post->post_type || $new === $old ) {
			return;
		}
		self::schedule_push();
	}

	public static function on_delete( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( GASF_EVENTS_CPT === $type ) {
			self::schedule_push();
		}
	}

	/**
	 * Queue one push, soon. Already-queued stays queued rather than being
	 * pushed further out, so a long feed run cannot starve the job by
	 * repeatedly resetting its timer.
	 */
	public static function schedule_push(): void {
		if ( ! self::ready() || wp_next_scheduled( self::CRON_PUSH ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DEBOUNCE, self::CRON_PUSH );
	}

	/** Daily sweep so the rolling window advances in a week with no edits. */
	public static function ensure_schedule(): void {
		if ( self::ready() && ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_DAILY );
		} elseif ( ! self::ready() ) {
			$ts = wp_next_scheduled( self::CRON_DAILY );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::CRON_DAILY );
			}
		}
	}

	public static function cron_run(): void {
		self::run( false );
	}

	/* ---- the computation ---------------------------------------------- */

	/**
	 * Regular hours as [ WEEKDAY => [ [openMin, closeMin], … ] ].
	 *
	 * Read from the live listing rather than stored locally, so the union rule
	 * always tracks whatever regular hours are actually published. Cached 12h —
	 * this is static config that changes about once a decade.
	 *
	 * @return array|\WP_Error
	 */
	public static function regular_hours( bool $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::REG_CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$loc = Google_Business_Profile::get_location( self::location(), 'regularHours' );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		$days = [ 'SUNDAY' => 0, 'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6 ];
		$out  = [];
		foreach ( (array) ( $loc['regularHours']['periods'] ?? [] ) as $p ) {
			$od = (string) ( $p['openDay'] ?? '' );
			if ( ! isset( $days[ $od ] ) ) {
				continue;
			}
			$open  = self::to_min( $p['openTime'] ?? [] );
			$close = self::to_min( $p['closeTime'] ?? [] );
			// A period closing on the following day (e.g. FRI 20:00 → SAT 02:00)
			// is expressed as minutes past that day's own midnight.
			if ( (string) ( $p['closeDay'] ?? $od ) !== $od || $close <= $open ) {
				$close += 1440;
			}
			$out[ $od ][] = [ $open, $close ];
		}
		foreach ( $out as $d => $iv ) {
			$out[ $d ] = self::merge( $iv );
		}
		set_transient( self::REG_CACHE, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Compute the full desired specialHourPeriods list for the window.
	 *
	 * @return array{periods:array,notes:array,events:int}|\WP_Error
	 */
	public static function build( ?array $regular_override = null ) {
		// The override exists for the one-time cutover: the intended regular
		// hours have to drive the computation BEFORE they are published, or the
		// union is taken against hours we are about to replace.
		$regular = null !== $regular_override ? $regular_override : self::regular_hours();
		if ( is_wp_error( $regular ) ) {
			return $regular;
		}
		$tz     = wp_timezone();
		$today  = new \DateTimeImmutable( 'today', $tz );
		$last   = $today->modify( '+' . ( self::window_days() - 1 ) . ' days' );
		$buffer = self::buffer_min();
		$notes  = [];

		$ids = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => [
				'relation' => 'AND',
				[ 'key' => Meta::END_TS, 'value' => $today->getTimestamp(), 'type' => 'NUMERIC', 'compare' => '>=' ],
				[ 'key' => Meta::START_TS, 'value' => $last->modify( '+1 day' )->getTimestamp(), 'type' => 'NUMERIC', 'compare' => '<=' ],
			],
		] );

		$by_date = [];
		$counted = 0;
		foreach ( $ids as $id ) {
			$status = (string) get_post_meta( $id, Meta::STATUS, true );
			if ( 'cancelled' === $status ) {
				continue; // a cancelled event does not open the hall
			}
			if ( get_post_meta( $id, Meta::ALL_DAY, true ) ) {
				// "All day" says nothing about when the doors are open, and
				// guessing would publish hours nobody agreed to.
				$notes[] = 'skipped all-day: ' . get_the_title( $id );
				continue;
			}
			$start = (string) get_post_meta( $id, Meta::START, true );
			$end   = (string) get_post_meta( $id, Meta::END, true );
			if ( '' === $start || '' === $end || $end === $start ) {
				$notes[] = 'skipped (no end time): ' . get_the_title( $id );
				continue;
			}
			try {
				$s = new \DateTimeImmutable( $start, $tz );
				$e = new \DateTimeImmutable( $end, $tz );
			} catch ( \Exception $ex ) {
				continue;
			}
			if ( $e <= $s ) {
				continue;
			}
			$s = $s->modify( '-' . $buffer . ' minutes' );
			$span = (int) round( ( $e->getTimestamp() - $s->getTimestamp() ) / 60 );
			if ( $span > self::MAX_SPAN ) {
				// Google cannot express a period of 24h or more; a multi-day
				// event needs per-day hours that the calendar does not carry.
				$notes[] = 'skipped (spans ' . round( $span / 60 ) . 'h): ' . get_the_title( $id );
				continue;
			}
			$date = $s->format( 'Y-m-d' );
			$midnight = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
			$open  = (int) round( ( $s->getTimestamp() - $midnight->getTimestamp() ) / 60 );
			$by_date[ $date ][] = [ $open, $open + $span ];
			$counted++;
		}

		// Fail-safe mirroring Feeds::run(): an empty result is inconclusive, and
		// publishing it would wipe every override in one PATCH.
		if ( ! $counted && ! $by_date ) {
			return [ 'periods' => [], 'notes' => $notes, 'events' => 0 ];
		}

		$periods = [];
		for ( $d = $today; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			$date = $d->format( 'Y-m-d' );
			$dow  = strtoupper( $d->format( 'l' ) );
			$reg  = $regular[ $dow ] ?? [];
			$ev   = $by_date[ $date ] ?? [];
			if ( ! $ev ) {
				continue; // regular hours already say the right thing
			}
			$union = self::merge( array_merge( $reg, $ev ) );
			if ( $union === self::merge( $reg ) ) {
				continue; // event sits inside the regular hours — nothing to override
			}
			foreach ( $union as $iv ) {
				$p = self::period( $date, $iv[0], $iv[1], $tz );
				if ( $p ) {
					$periods[] = $p;
				}
			}
		}

		return [ 'periods' => $periods, 'notes' => $notes, 'events' => $counted ];
	}

	/* ---- push --------------------------------------------------------- */

	/**
	 * Rebuild and publish. Returns stats; never throws.
	 *
	 * @return array{pushed:bool,periods:int,skipped:string,error:string,notes:array}
	 */
	public static function run( bool $dry = false ): array {
		$out = [ 'pushed' => false, 'periods' => 0, 'skipped' => '', 'error' => '', 'notes' => [], 'dry' => $dry ];
		if ( ! self::enabled() ) {
			$out['skipped'] = 'disabled';
			return $out;
		}
		if ( '' === self::location() ) {
			$out['error'] = 'no location id configured';
			return $out;
		}
		if ( ! Google_Business_Profile::available() ) {
			$out['error'] = 'key file unreadable: ' . Google_Business_Profile::key_path();
			return $out;
		}

		$built = self::build();
		if ( is_wp_error( $built ) ) {
			$out['error'] = $built->get_error_message();
			return $out;
		}
		$out['periods'] = count( $built['periods'] );
		$out['notes']   = $built['notes'];
		$out['events']  = $built['events'];

		// Never publish an empty set off the back of an empty query — that is
		// indistinguishable from "the calendar failed to load" and would clear
		// every override at once.
		if ( ! $built['events'] && ! $out['periods'] ) {
			$out['skipped'] = 'no events in window (inconclusive, not publishing an empty set)';
			return $out;
		}

		$body = [ 'specialHours' => [ 'specialHourPeriods' => $built['periods'] ] ];
		$hash = md5( (string) wp_json_encode( $body ) );
		$out['hash'] = $hash;

		if ( $dry ) {
			$out['payload'] = $body;
			$out['would_change'] = ( $hash !== (string) get_option( self::OPT_HASH, '' ) );
			return $out;
		}
		if ( $hash === (string) get_option( self::OPT_HASH, '' ) ) {
			$out['skipped'] = 'unchanged';
			return $out;
		}

		$res = Google_Business_Profile::patch_location( self::location(), $body, 'specialHours' );
		if ( is_wp_error( $res ) ) {
			$out['error'] = $res->get_error_message();
			return $out;
		}
		update_option( self::OPT_HASH, $hash, false );
		update_option( self::OPT_LAST, [ 'ts' => time(), 'periods' => $out['periods'], 'events' => $built['events'] ], false );
		$out['pushed'] = true;
		return $out;
	}

	/* ---- helpers ------------------------------------------------------ */

	/** {hours,minutes} → minutes past midnight. */
	private static function to_min( array $t ): int {
		return ( (int) ( $t['hours'] ?? 0 ) * 60 ) + (int) ( $t['minutes'] ?? 0 );
	}

	/** Minutes past midnight → {hours,minutes}; 1440 is Google's valid "24:00". */
	private static function from_min( int $m ): array {
		$out = [ 'hours' => intdiv( $m, 60 ) ];
		if ( $m % 60 ) {
			$out['minutes'] = $m % 60;
		}
		return $out;
	}

	/**
	 * Sort and coalesce. Touching intervals merge (18:00–22:00 + 22:00–23:00 is
	 * one 18:00–23:00 opening, not two periods with a zero-length gap).
	 */
	private static function merge( array $intervals ): array {
		if ( ! $intervals ) {
			return [];
		}
		usort( $intervals, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
		$out = [ array_shift( $intervals ) ];
		foreach ( $intervals as $iv ) {
			$tail = count( $out ) - 1;
			if ( $iv[0] <= $out[ $tail ][1] ) {
				$out[ $tail ][1] = max( $out[ $tail ][1], $iv[1] );
			} else {
				$out[] = $iv;
			}
		}
		return $out;
	}

	/**
	 * One specialHourPeriod. Minutes past 1440 roll onto endDate, which is how
	 * a past-midnight close is expressed; Google allows endDate to be at most
	 * one day after startDate, which the MAX_SPAN check already guarantees.
	 */
	private static function period( string $date, int $open, int $close, \DateTimeZone $tz ): ?array {
		if ( $close <= $open || ( $close - $open ) > self::MAX_SPAN ) {
			return null;
		}
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );
		$p = [
			'startDate' => [ 'year' => $y, 'month' => $m, 'day' => $d ],
			'openTime'  => self::from_min( $open ),
		];
		if ( $close > 1440 ) {
			$next = ( new \DateTimeImmutable( $date, $tz ) )->modify( '+1 day' );
			$p['endDate']   = [ 'year' => (int) $next->format( 'Y' ), 'month' => (int) $next->format( 'n' ), 'day' => (int) $next->format( 'j' ) ];
			$p['closeTime'] = self::from_min( $close - 1440 );
		} else {
			$p['closeTime'] = self::from_min( $close );
		}
		return $p;
	}
}
