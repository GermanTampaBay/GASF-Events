<?php
/**
 * "Add to Calendar" — one helper that builds Google / Outlook.com / Office 365 /
 * Yahoo deep links + a per-event .ics download (which also serves Apple
 * Calendar, since Apple has no web URL). Also serves the .ics via a lightweight
 * query endpoint. See docs/ARCHITECTURE.md §5.6. (Full subscribable feeds: P7.)
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Add_To_Calendar {

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve_ics' ] );
		add_action( 'init', [ $this, 'add_rewrite' ] );
	}

	public function add_query_var(): void {
		add_rewrite_tag( '%gasf_ics%', '([^&]+)' );
		add_rewrite_tag( '%gasf_feed_ics%', '([^&]+)' ); // per-feed passthrough .ics
	}

	/** Pretty subscribe URL: /<slug>/calendar.ics → ?gasf_ics=all. */
	public function add_rewrite(): void {
		add_rewrite_rule( '^' . GASF_EVENTS_REWRITE_SLUG . '/calendar\.ics$', 'index.php?gasf_ics=all', 'top' );
	}

	/**
	 * Serve .ics:
	 *   ?gasf_ics=event&event=<id>  → one event (add-to-calendar / Apple)
	 *   ?gasf_ics=all               → the whole upcoming calendar (subscribable feed)
	 */
	public function maybe_serve_ics(): void {

		// Per-feed passthrough: ?gasf_feed_ics=<token> re-emits that feed's
		// fetched → filtered → prefixed events as a subscribable .ics, WITHOUT
		// requiring them to be stored in the Events calendar. Token = the feed's
		// unguessable ics_token. Cached 15 min (short 2-min hold on fetch error,
		// falling back to the last good build) so subscriber polling can't hammer
		// the upstream source.
		$feed_tok = (string) ( get_query_var( 'gasf_feed_ics' ) ?: ( $_GET['gasf_feed_ics'] ?? '' ) );
		if ( '' !== $feed_tok ) {
			$feed = Feeds::find_by_ics_token( $feed_tok );
			if ( ! $feed ) {
				status_header( 404 );
				nocache_headers();
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo 'Not found';
				exit;
			}
			$id  = (string) $feed['id'];
			$key = 'gasf_feed_ics_' . $id;
			$doc = get_transient( $key );
			if ( false === $doc ) {
				$res = Feeds::fetch_transformed( $feed );
				if ( '' === $res['error'] ) {
					$doc = self::ics_document_from_norm( $res['events'], (string) ( $feed['label'] ?? 'Feed' ) );
					set_transient( $key, $doc, 15 * MINUTE_IN_SECONDS );
					update_option( 'gasf_feed_ics_lg_' . $id, $doc, false ); // last-good fallback
				} else {
					$doc = (string) get_option( 'gasf_feed_ics_lg_' . $id, '' );
					if ( '' === $doc ) {
						$doc = self::ics_document_from_norm( [], (string) ( $feed['label'] ?? 'Feed' ) );
					}
					set_transient( $key, $doc, 2 * MINUTE_IN_SECONDS );
				}
			}
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="feed-' . $id . '.ics"' );
			header( 'Cache-Control: public, max-age=900' );
			header( 'X-WR-CALNAME: ' . (string) ( $feed['label'] ?? 'Feed' ) );
			echo $doc; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		$mode = (string) ( get_query_var( 'gasf_ics' ) ?: ( $_GET['gasf_ics'] ?? '' ) );

		if ( 'event' === $mode ) {
			$event = Event::get( (int) ( $_GET['event'] ?? 0 ) );
			// Published only — don't leak a draft/private event's details via id guessing.
			if ( ! $event || 'publish' !== get_post_status( $event->id() ) ) {
				status_header( 404 );
				exit;
			}
			nocache_headers();
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="event-' . $event->id() . '.ics"' );
			echo self::ics_document( [ $event ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( 'all' === $mode ) {
			// Cache the built feed (unauthenticated 500-row build otherwise runs on
			// every poll). Invalidated by TTL; subscribers re-poll periodically.
			$body = get_transient( 'gasf_ics_all' );
			if ( false === $body ) {
				$body = self::ics_document( Calendar::upcoming( 500, time() - 7 * DAY_IN_SECONDS ) );
				set_transient( 'gasf_ics_all', $body, 15 * MINUTE_IN_SECONDS );
			}
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="gasf-calendar.ics"' );
			header( 'Cache-Control: public, max-age=900' );
			header( 'X-WR-CALNAME: ' . get_bloginfo( 'name' ) . ' Events' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/** The subscribable feed URL (https) and its webcal:// form. */
	public static function subscribe_url( bool $webcal = false ): string {
		$url = home_url( '/' . GASF_EVENTS_REWRITE_SLUG . '/calendar.ics' );
		return $webcal ? preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}

	/* ---- Provider links ----------------------------------------------- */

	/**
	 * @return array<int,array{key:string,label:string,url:string}>
	 */
	public static function links( Event $event ): array {
		$start = $event->start();
		$end   = $event->end() ?: $start;
		if ( ! $start ) {
			return [];
		}
		$title    = $event->title();
		$details  = self::plain( $event->description() ) . "\n\n" . $event->permalink();
		$location = self::location_string( $event );

		$g_dates  = self::utc( $start ) . '/' . self::utc( $end );
		// With the site offset: Outlook read the bare form in the VIEWER's
		// timezone, so a visitor outside Florida got the wrong hour.
		$iso_s    = $start->format( 'c' );
		$iso_e    = $end->format( 'c' );

		return [
			[
				'key'   => 'google',
				'label' => __( 'Google Calendar', 'gasf-events' ),
				'url'   => add_query_arg( array_map( 'rawurlencode', [
					'action'  => 'TEMPLATE',
					'text'    => $title,
					'dates'   => $g_dates,
					'details' => $details,
					'location' => $location,
				] ), 'https://calendar.google.com/calendar/render' ),
			],
			[
				'key'   => 'outlook',
				'label' => __( 'Outlook.com', 'gasf-events' ),
				'url'   => self::outlook( 'https://outlook.live.com/calendar/0/deeplink/compose', $title, $iso_s, $iso_e, $details, $location ),
			],
			[
				'key'   => 'office365',
				'label' => __( 'Office 365', 'gasf-events' ),
				'url'   => self::outlook( 'https://outlook.office.com/calendar/0/deeplink/compose', $title, $iso_s, $iso_e, $details, $location ),
			],
			[
				'key'   => 'yahoo',
				'label' => __( 'Yahoo', 'gasf-events' ),
				'url'   => add_query_arg( array_map( 'rawurlencode', [
					'v'      => '60',
					'title'  => $title,
					'st'     => self::utc( $start ),
					'et'     => self::utc( $end ),
					'desc'   => $details,
					'in_loc' => $location,
				] ), 'https://calendar.yahoo.com/' ),
			],
			[
				'key'   => 'apple',
				'label' => __( 'Apple / Download .ics', 'gasf-events' ),
				'url'   => self::ics_url( $event ),
			],
		];
	}

	private static function outlook( string $base, string $title, string $iso_s, string $iso_e, string $details, string $location ): string {
		return add_query_arg( array_map( 'rawurlencode', [
			'path'    => '/calendar/action/compose',
			'rru'     => 'addevent',
			'subject' => $title,
			'startdt' => $iso_s,
			'enddt'   => $iso_e,
			'body'    => $details,
			'location' => $location,
		] ), $base );
	}

	public static function ics_url( Event $event ): string {
		return add_query_arg( [ 'gasf_ics' => 'event', 'event' => $event->id() ], home_url( '/' ) );
	}

	/* ---- .ics document ------------------------------------------------ */

	/**
	 * Build a VCALENDAR for one or more events (reused by the feeds in P7).
	 */
	public static function ics_document( array $events ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//German-American Society//GASF Events//EN',
			'CALSCALE:GREGORIAN',
		];
		foreach ( $events as $event ) {
			if ( ! $event instanceof Event ) {
				continue;
			}
			$start = $event->start();
			$end   = $event->end() ?: $start;
			if ( ! $start ) {
				continue;
			}
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:gasf-' . $event->id() . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			if ( $event->is_all_day() ) {
				$lines[] = 'DTSTART;VALUE=DATE:' . $start->format( 'Ymd' );
				$lines[] = 'DTEND;VALUE=DATE:' . $end->modify( '+1 day' )->format( 'Ymd' );
			} else {
				$lines[] = 'DTSTART:' . self::utc( $start );
				$lines[] = 'DTEND:' . self::utc( $end );
			}
			$lines[] = 'SUMMARY:' . self::esc( $event->title() );
			// The permalink lives in the standard URL property below, NOT jammed
			// into the description — otherwise a site-to-site sync copies this
			// site's link into the body of the other calendar's event.
			$lines[] = 'DESCRIPTION:' . self::esc( self::plain( $event->description() ) );
			$lines[] = 'LOCATION:' . self::esc( self::location_string( $event ) );
			$lines[] = 'URL:' . $event->permalink();
			if ( 'cancelled' === $event->status() ) {
				$lines[] = 'STATUS:CANCELLED';
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	/**
	 * Build a VCALENDAR from normalized feed event arrays (uid,title,description,
	 * start,end,all_day,status,location,rrule) rather than local Event posts.
	 * Used by the per-feed passthrough .ics endpoint. start/end are 'Y-m-d H:i:s'
	 * strings in the site timezone; timed events are emitted in UTC (…Z) so no
	 * VTIMEZONE block is needed.
	 */
	public static function ics_document_from_norm( array $events, string $calname = '' ): string {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$tz    = wp_timezone();
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//German-American Society//GASF Events//EN',
			'CALSCALE:GREGORIAN',
		];
		if ( '' !== $calname ) {
			$lines[] = 'X-WR-CALNAME:' . self::esc( $calname );
		}
		foreach ( $events as $ev ) {
			$start = (string) ( $ev['start'] ?? '' );
			if ( '' === $start ) {
				continue;
			}
			$end     = (string) ( $ev['end'] ?? '' ) ?: $start;
			$all_day = ! empty( $ev['all_day'] );
			$uid     = (string) ( $ev['uid'] ?? '' );
			if ( '' === $uid ) {
				$uid = md5( (string) ( $ev['title'] ?? '' ) . $start );
			}
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:gasf-feed-' . preg_replace( '/[^A-Za-z0-9_.\-]/', '', $uid ) . '@' . $host;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			if ( $all_day ) {
				$lines[] = 'DTSTART;VALUE=DATE:' . self::local_ymd( $start, $tz );
				// All-day DTEND is exclusive: the day after the (inclusive) end date.
				try {
					$ed = ( new \DateTimeImmutable( $end, $tz ) )->modify( '+1 day' )->format( 'Ymd' );
				} catch ( \Exception $e ) {
					$ed = self::local_ymd( $end, $tz );
				}
				$lines[] = 'DTEND;VALUE=DATE:' . $ed;
			} else {
				$lines[] = 'DTSTART:' . self::local_utc( $start, $tz );
				$lines[] = 'DTEND:' . self::local_utc( $end, $tz );
			}
			$lines[] = 'SUMMARY:' . self::esc( (string) ( $ev['title'] ?? '' ) );
			if ( ! empty( $ev['description'] ) ) {
				$lines[] = 'DESCRIPTION:' . self::esc( self::plain( (string) $ev['description'] ) );
			}
			if ( ! empty( $ev['location'] ) ) {
				$lines[] = 'LOCATION:' . self::esc( (string) $ev['location'] );
			}
			if ( ! empty( $ev['rrule'] ) ) {
				$lines[] = 'RRULE:' . $ev['rrule']; // syntactic semicolons — never escaped
			}
			if ( 'cancelled' === ( $ev['status'] ?? '' ) ) {
				$lines[] = 'STATUS:CANCELLED';
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	private static function local_utc( string $local, \DateTimeZone $tz ): string {
		try {
			return ( new \DateTimeImmutable( $local, $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		} catch ( \Exception $e ) {
			return gmdate( 'Ymd\THis\Z' );
		}
	}

	private static function local_ymd( string $local, \DateTimeZone $tz ): string {
		try {
			return ( new \DateTimeImmutable( $local, $tz ) )->format( 'Ymd' );
		} catch ( \Exception $e ) {
			return gmdate( 'Ymd' );
		}
	}

	/** RFC 5545 line folding: split content lines longer than 75 octets. */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out = '';
		$pos = 0;
		$len = strlen( $line );
		while ( $pos < $len ) {
			$max = 0 === $pos ? 75 : 74;
			$max = min( $max, $len - $pos );
			// Don't split inside a multibyte UTF-8 sequence (ü/ö/ä/ß): back off the
			// cut while the next byte is a continuation byte (10xxxxxx).
			while ( $max > 1 && $pos + $max < $len && ( ord( $line[ $pos + $max ] ) & 0xC0 ) === 0x80 ) {
				$max--;
			}
			$out .= ( 0 === $pos ? '' : "\r\n " ) . substr( $line, $pos, $max );
			$pos += $max;
		}
		return $out;
	}

	/* ---- helpers ------------------------------------------------------ */

	private static function utc( \DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	public static function location_string( Event $event ): string {
		$v = $event->venue();
		$parts = array_filter( [ $v['name'] ?? '', $v['street'] ?? '', trim( ( $v['city'] ?? '' ) . ', ' . ( $v['state'] ?? '' ) . ' ' . ( $v['zip'] ?? '' ) ) ] );
		return implode( ', ', $parts );
	}

	private static function plain( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}

	/** Escape per RFC 5545 (commas, semicolons, newlines). */
	private static function esc( string $text ): string {
		$text = str_replace( [ "\\", "\n", "\r", ',', ';' ], [ '\\\\', '\\n', '', '\\,', '\\;' ], $text );
		return $text;
	}
}
