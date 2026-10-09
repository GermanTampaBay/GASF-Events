<?php
/**
 * Welton Brewing "open" blurb.
 *
 * The club shares its property with Welton Brewing Co. & Oyster Bar, so an event
 * page (or any page) can show a contextual note when the brewery is open. Native
 * and self-contained — no dependency on any legacy mu-plugin, snippet, or external
 * gate. The hours below are the single source of truth for GASF Events surfaces.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Welton {

	/**
	 * Welton hours as [open, close] minutes-of-day, keyed by ISO weekday
	 * (1=Mon … 7=Sun); false = closed. Edit here to keep the blurb accurate.
	 */
	private const HOURS = [
		1 => false,            // Mon closed
		2 => false,            // Tue closed
		3 => [ 16 * 60, 21 * 60 ], // Wed 4–9pm
		4 => [ 16 * 60, 21 * 60 ], // Thu 4–9pm
		5 => [ 11 * 60, 22 * 60 ], // Fri 11am–10pm
		6 => [ 11 * 60, 22 * 60 ], // Sat 11am–10pm
		7 => [ 12 * 60, 20 * 60 ], // Sun noon–8pm
	];

	private const LINK = '<a href="https://www.weltonbrewingcompany.com/" target="_blank" rel="noopener">Welton Brewing Co. &amp; Oyster Bar</a>';

	/**
	 * Oktoberfest override. During an Oktoberfest week the HOURS table above is
	 * not trustworthy — Welton runs its own schedule around the festival — so
	 * every blurb is replaced by a pointer to their site rather than a time we
	 * would be guessing at.
	 */
	private const OKT_NOTE = 'Visit <a href="https://weltonbrewingcompany.com/" target="_blank" rel="noopener">weltonbrewingcompany.com</a> for their open hours during Oktoberfest.';

	public function register_hooks(): void {
		add_shortcode( 'gasf_welton_status', [ $this, 'shortcode' ] );
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ __CLASS__, 'flush_week_cache' ], 10, 1 );
		add_action( 'before_delete_post', [ __CLASS__, 'flush_week_cache' ], 10, 1 );
	}

	/**
	 * [gasf_welton_status] — auto-detects the current gasf_event, or accepts
	 * event_start/event_end="Y-m-d H:i:s"; with no event it falls back to the
	 * generic "open now / opens at" message.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'event_start' => '', 'event_end' => '' ], $atts, 'gasf_welton_status' );
		$tz   = wp_timezone();

		if ( '' !== $atts['event_start'] ) {
			try {
				$start = new \DateTimeImmutable( $atts['event_start'], $tz );
				$end   = '' !== $atts['event_end'] ? new \DateTimeImmutable( $atts['event_end'], $tz ) : null;
				return self::render_event( $start, $end, $tz );
			} catch ( \Exception $e ) {
				return '';
			}
		}

		if ( is_singular( GASF_EVENTS_CPT ) ) {
			$event = Event::get( get_queried_object_id() );
			if ( $event ) {
				return self::blurb( $event );
			}
		}

		return self::render_generic( $tz );
	}

	/** Event-aware blurb (used directly by the single-event template). */
	public static function blurb( Event $event ): string {
		$start = $event->start();
		return $start ? self::render_event( $start, $event->end(), wp_timezone() ) : '';
	}

	/** Show a note if the event overlaps Welton's hours that day. */
	private static function render_event( \DateTimeImmutable $start, ?\DateTimeImmutable $end, \DateTimeZone $tz ): string {
		$now = new \DateTimeImmutable( 'now', $tz );
		$end = $end ?: $start->modify( '+2 hours' );
		if ( $end < $now ) {
			return ''; // event already over
		}

		// Keyed on the EVENT's week, not today's: a December event viewed during
		// Oktoberfest still gets the normal blurb. Checked before the HOURS lookup
		// and the overlap test, because during Oktoberfest we cannot claim Welton
		// is closed or that there is no overlap — we do not know their hours.
		if ( self::is_oktoberfest_week( $start ) ) {
			return '<div class="gasf-welton">' . self::OKT_NOTE . '</div>';
		}

		$today = self::HOURS[ (int) $start->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return ''; // Welton closed that day
		}

		$w_open  = $start->setTime( intdiv( $today[0], 60 ), $today[0] % 60 );
		$w_close = $start->setTime( intdiv( $today[1], 60 ), $today[1] % 60 );
		if ( $end <= $w_open || $start >= $w_close ) {
			return ''; // no overlap with Welton's hours
		}

		$is_today = ( $start->format( 'Y-m-d' ) === $now->format( 'Y-m-d' ) );
		if ( $is_today && self::open_now( $now ) ) {
			$msg = self::LINK . ' is open! Swing by for a delicious meal or a craft brew!';
		} else {
			$msg = 'Good news! ' . self::LINK . ' &mdash; the on-site restaurant and brewery &mdash; will be open to enjoy a meal or a craft beer before or after the event.';
		}
		return '<div class="gasf-welton">' . $msg . '</div>';
	}

	/** Generic "open now / opens at" message when there's no event context. */
	private static function render_generic( \DateTimeZone $tz ): string {
		$now = new \DateTimeImmutable( 'now', $tz );
		// Deliberately ahead of the HOURS lookup, so the note still appears on a
		// Monday or Tuesday. Those days are "closed" in our table and normally
		// render nothing — but during Oktoberfest that is exactly the assumption
		// most likely to be wrong.
		if ( self::is_oktoberfest_week( $now ) ) {
			return '<div class="gasf-welton">' . self::OKT_NOTE . '</div>';
		}
		$today = self::HOURS[ (int) $now->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return '';
		}
		$now_min = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );
		if ( $now_min >= $today[1] ) {
			return ''; // already closed for the day
		}
		if ( $now_min >= $today[0] ) {
			$msg = self::LINK . ' is open on-site right now &mdash; fresh Maine oysters, lobster rolls &amp; craft beer. Stop in!';
		} else {
			$open     = $now->setTime( intdiv( $today[0], 60 ), $today[0] % 60 );
			$open_str = str_replace( ':00', '', $open->format( 'g:i a' ) );
			$msg      = self::LINK . ' opens at ' . $open_str . ' today, right here on the property. Plan a visit!';
		}
		return '<div class="gasf-welton">' . $msg . '</div>';
	}

	/* ---- Oktoberfest week detection ---------------------------------- */

	/**
	 * Is the week containing $when a GASF Oktoberfest week?
	 *
	 * Weeks run Sunday → Saturday. The week counts when BOTH hold:
	 *   - its Sunday falls in September or October, which keeps the rule inside
	 *     the festival season so a stray listing in another month cannot
	 *     silently rewrite the blurb; and
	 *   - the Saturday of that week carries an event titled exactly Oktoberfest.
	 *
	 * The Saturday is the anchor because that is the day the festival always
	 * runs, so the note goes up for the whole week leading to it.
	 */
	public static function is_oktoberfest_week( \DateTimeImmutable $when ): bool {
		// 'w' is 0 for Sunday, so this walks back to the week's own Sunday.
		$sunday = $when->modify( '-' . (int) $when->format( 'w' ) . ' days' );
		$month  = (int) $sunday->format( 'n' );
		if ( 9 !== $month && 10 !== $month ) {
			return false;
		}
		return self::saturday_has_oktoberfest( $sunday->modify( '+6 days' ) );
	}

	/**
	 * The title is literally "Oktoberfest" and nothing else.
	 *
	 * No trimming, no case folding, no punctuation stripping. These all FAIL:
	 *   " Oktoberfest"   "Oktoberfest!"   "Oktoberfest 2027"   "Oktoberfest Tampa"
	 *   "Are You Ready for Oktoberfest Dinner and Dance"
	 *
	 * If a year's festival is titled anything else, retitle the event.
	 */
	public static function is_oktoberfest_title( string $title ): bool {
		return 'Oktoberfest' === $title;
	}

	/**
	 * Does $saturday carry a published, non-cancelled Oktoberfest?
	 *
	 * Cached per date because this runs on ordinary page loads. The transient is
	 * cleared whenever an event is saved or deleted (see register_hooks), so a
	 * retitled or cancelled festival takes effect at once rather than whenever
	 * the cache happens to lapse.
	 */
	private static function saturday_has_oktoberfest( \DateTimeImmutable $saturday ): bool {
		$key    = self::cache_key( $saturday );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$day  = $saturday->setTime( 0, 0 );
		$next = $day->modify( '+1 day' );
		$ids  = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'publish',
			'numberposts'      => 50,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => [ [
				'key'     => Meta::START_TS,
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
				'value'   => [ $day->getTimestamp(), $next->getTimestamp() - 1 ],
			] ],
		] );
		$found = false;
		foreach ( $ids as $id ) {
			if ( 'cancelled' === (string) get_post_meta( $id, Meta::STATUS, true ) ) {
				continue; // a cancelled festival is not an Oktoberfest week
			}
			if ( self::is_oktoberfest_title( (string) get_the_title( $id ) ) ) {
				$found = true;
				break;
			}
		}
		set_transient( $key, $found ? '1' : '0', 6 * HOUR_IN_SECONDS );
		return $found;
	}

	private static function cache_key( \DateTimeImmutable $saturday ): string {
		return 'gasf_welton_okt_' . $saturday->format( 'Ymd' );
	}

	/**
	 * Drop the cached answer for the week an event belongs to, so renaming or
	 * cancelling the festival is reflected on the next page load.
	 */
	public static function flush_week_cache( $post_id ): void {
		if ( GASF_EVENTS_CPT !== get_post_type( $post_id ) ) {
			return;
		}
		$start = (string) get_post_meta( $post_id, Meta::START, true );
		if ( '' === $start ) {
			return;
		}
		try {
			$d = new \DateTimeImmutable( $start, wp_timezone() );
		} catch ( \Exception $e ) {
			return;
		}
		// Forward to that week's Saturday, which is what the cache is keyed on.
		$saturday = $d->modify( '+' . ( 6 - (int) $d->format( 'w' ) ) . ' days' );
		delete_transient( self::cache_key( $saturday ) );
	}

	private static function open_now( \DateTimeImmutable $now ): bool {
		$today = self::HOURS[ (int) $now->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return false;
		}
		$min = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );
		return ( $min >= $today[0] && $min < $today[1] );
	}
}
