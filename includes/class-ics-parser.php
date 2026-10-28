<?php
/**
 * Minimal, dependency-free iCalendar (.ics) parser — line-unfolds, extracts
 * VEVENT blocks, and returns one record per event. Reused for inbound feed
 * ingest (→ GASF Calendar) and the outbound Google push. Ported from the
 * module-26 parser. See docs/ARCHITECTURE.md (multi-feed router).
 *
 * Recurrence: the Google destination forwards RRULE verbatim and lets Google
 * expand it. The GASF Calendar stores flat posts, so expand() materialises a
 * bounded window of occurrences here (v0.35.0) — before that a weekly ICS
 * event landed on the calendar exactly once, as its first date.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class ICS_Parser {

	/**
	 * @return array<int,array> rich VEVENT records (uid,summary,description,
	 *         location,url,dtstart,dtstart_allday,dtstart_tzid,dtend,…,rrule,
	 *         exdate[],recurrence_id)
	 */
	public static function parse( string $raw ): array {
		$raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		// RFC 5545 line unfolding: a leading space/tab continues the prior line.
		$raw = preg_replace( '/\n[ \t]/', '', $raw );
		$lines = explode( "\n", $raw );

		$events = [];
		$cur    = null;
		$in_tz  = false;
		foreach ( $lines as $line ) {
			$u = strtoupper( $line );
			if ( 'BEGIN:VTIMEZONE' === $u ) { $in_tz = true; continue; }
			if ( 'END:VTIMEZONE' === $u )   { $in_tz = false; continue; }
			if ( $in_tz ) { continue; }
			if ( 'BEGIN:VEVENT' === $u ) { $cur = []; continue; }
			if ( 'END:VEVENT' === $u ) {
				if ( is_array( $cur ) && ! empty( $cur['uid'] ) ) {
					$events[] = $cur;
				}
				$cur = null;
				continue;
			}
			if ( null === $cur ) { continue; }

			$colon = strpos( $line, ':' );
			if ( false === $colon ) { continue; }
			$name_params = substr( $line, 0, $colon );
			$value       = substr( $line, $colon + 1 );
			$parts       = explode( ';', $name_params );
			$name        = strtoupper( array_shift( $parts ) );
			$params      = [];
			foreach ( $parts as $p ) {
				$kv = explode( '=', $p, 2 );
				if ( 2 === count( $kv ) ) {
					$params[ strtoupper( $kv[0] ) ] = $kv[1];
				}
			}

			switch ( $name ) {
				case 'UID':         $cur['uid'] = trim( $value ); break;
				case 'SUMMARY':     $cur['summary'] = self::unescape( $value ); break;
				case 'DESCRIPTION': $cur['description'] = self::unescape( $value ); break;
				case 'LOCATION':    $cur['location'] = self::unescape( $value ); break;
				case 'URL':         $cur['url'] = trim( $value ); break;
				case 'RRULE':       $cur['rrule'] = trim( $value ); break;
				case 'STATUS':      $cur['status'] = strtoupper( trim( $value ) ); break;
				case 'DTSTART':     self::set_dt( $cur, 'dtstart', $value, $params ); break;
				case 'DTEND':       self::set_dt( $cur, 'dtend', $value, $params ); break;
				// An instance of a recurring event that was edited on its own: same
				// UID as the master, RECURRENCE-ID = the original start it replaces.
				case 'RECURRENCE-ID': self::set_dt( $cur, 'recurrence_id', $value, $params ); break;
				// Dates removed from a recurring event. May repeat, and each line
				// may carry several comma-separated values.
				case 'EXDATE':
					foreach ( explode( ',', $value ) as $one ) {
						$ex = [];
						self::set_dt( $ex, 'dt', $one, $params );
						if ( isset( $ex['dt'] ) ) {
							$cur['exdate'][] = $ex;
						}
					}
					break;
			}
		}
		return $events;
	}

	private static function set_dt( ?array &$cur, string $field, string $value, array $params ): void {
		$value   = trim( $value );
		$is_date = ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) || preg_match( '/^\d{8}$/', $value );
		if ( $is_date ) {
			$cur[ $field ]            = substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
			$cur[ $field . '_allday' ] = true;
			return;
		}
		// Timed: YYYYMMDDTHHmmss[Z]
		$z = ( 'Z' === substr( $value, -1 ) );
		$v = rtrim( $value, 'Z' );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $v, $m ) ) {
			$cur[ $field ]             = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}" . ( $z ? 'Z' : '' );
			$cur[ $field . '_allday' ] = false;
			// No Z and no TZID is RFC 5545 "floating" time: local time wherever it
			// is read, which here means the site timezone. It used to be read as
			// UTC, which put every floating feed 4–5 hours off.
			$cur[ $field . '_tzid' ]   = $z ? 'UTC' : ( $params['TZID'] ?? '' );
		}
	}

	private static function unescape( string $v ): string {
		return str_replace( [ '\\n', '\\N', '\\,', '\\;', '\\\\' ], [ "\n", "\n", ',', ';', '\\' ], trim( $v ) );
	}

	/* ---- Mapping to our normalized ingest shape ----------------------- */

	/**
	 * Convert a rich VEVENT record into the Event_Ingest normalized shape
	 * (start/end as site-local 'Y-m-d H:i:s'). Returns null if no usable start.
	 */
	public static function to_norm( array $rec ): ?array {
		$start = self::to_local( $rec['dtstart'] ?? '', ! empty( $rec['dtstart_allday'] ), $rec['dtstart_tzid'] ?? '' );
		if ( '' === $start ) {
			return null;
		}
		$all_day = ! empty( $rec['dtstart_allday'] );
		if ( ! empty( $rec['dtend'] ) ) {
			$end = self::to_local( $rec['dtend'], ! empty( $rec['dtend_allday'] ), $rec['dtend_tzid'] ?? '' );
			// ICS all-day DTEND is exclusive — pull back one second for our model.
			if ( $all_day && $end ) {
				try { $end = ( new \DateTimeImmutable( $end, wp_timezone() ) )->modify( '-1 second' )->format( 'Y-m-d H:i:s' ); } catch ( \Exception $e ) {}
			}
		} else {
			$end = $start;
		}

		// The event URL is carried as a SEPARATE field — never appended to the
		// description text. Appending it made a synced copy render the source
		// site's permalink as a stray link in its body; events should read as
		// native to the calendar they land on (Event_Ingest decides what, if
		// anything, to do with source_url per the feed's link setting).
		$desc = trim( (string) ( $rec['description'] ?? '' ) );

		$exdates = [];
		foreach ( (array) ( $rec['exdate'] ?? [] ) as $ex ) {
			$local = self::to_local( (string) ( $ex['dt'] ?? '' ), ! empty( $ex['dt_allday'] ), $ex['dt_tzid'] ?? '' );
			if ( '' !== $local ) {
				$exdates[] = [ 'local' => $local, 'date_only' => ! empty( $ex['dt_allday'] ) ];
			}
		}

		return [
			'uid'           => (string) $rec['uid'],
			'title'         => (string) ( $rec['summary'] ?? '(untitled)' ),
			'description'   => $desc,
			'start'         => $start,
			'end'           => $end,
			'all_day'       => $all_day,
			'status'        => ( isset( $rec['status'] ) && 'CANCELLED' === $rec['status'] ) ? 'cancelled' : '',
			'source_url'    => (string) ( $rec['url'] ?? '' ),
			'cover_url'     => '',
			'cover_id'      => '',
			'is_series'     => false,
			'series_id'     => '',
			'location'      => (string) ( $rec['location'] ?? '' ),
			'rrule'         => (string) ( $rec['rrule'] ?? '' ),
			'exdates'       => $exdates,
			'recurrence_id' => ! empty( $rec['recurrence_id'] )
				? self::to_local( (string) $rec['recurrence_id'], ! empty( $rec['recurrence_id_allday'] ), $rec['recurrence_id_tzid'] ?? '' )
				: '',
		];
	}

	private static function to_local( string $value, bool $all_day, string $tzid ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( $all_day ) {
			return $value . ' 00:00:00';
		}
		try {
			$tz = self::tz( $tzid );
			$dt = new \DateTimeImmutable( rtrim( $value, 'Z' ), $tz );
			return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/** '' (floating time) is the site timezone; an unknown TZID falls back to UTC. */
	private static function tz( string $tzid ): \DateTimeZone {
		if ( '' === $tzid ) {
			return wp_timezone();
		}
		try {
			return new \DateTimeZone( $tzid );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/* ---- Recurrence expansion (GASF destination only) ------------------ */

	/** Never materialise more than this many dates for one recurring event. */
	const MAX_INSTANCES = 104;

	/**
	 * Expand recurring events into flat occurrences inside [$from_ts, $to_ts].
	 *
	 * - A record with RRULE becomes one event per generated date, each with a
	 *   stable uid `<uid>@<YYYYMMDDTHHMMSS>` (local start) and shared
	 *   series_id `ics-<uid>`, so Event_Ingest dedups per date and groups them
	 *   like a Facebook recurring event.
	 * - EXDATE removes dates; a RECURRENCE-ID record replaces the generated
	 *   instance for its date (same uid scheme, so it dedups into that slot).
	 * - Non-recurring records pass through untouched, uid and all, so events
	 *   already on the calendar keep their dedup key.
	 *
	 * Supported RRULE parts: FREQ (DAILY/WEEKLY/MONTHLY/YEARLY), INTERVAL,
	 * COUNT, UNTIL, BYDAY (weekly list; monthly ordinal such as 2TU or -1FR),
	 * BYMONTHDAY (single). Anything else is ignored and the rule steps by FREQ
	 * alone, which is wrong less often than dropping every date but the first.
	 *
	 * @param array $events Normalized events from to_norm().
	 * @return array Normalized events, recurring ones replaced by instances.
	 */
	public static function expand( array $events, int $from_ts, int $to_ts ): array {
		$overrides = []; // "<uid>|<local start>" => instance event
		$masters   = [];
		foreach ( $events as $ev ) {
			$rid = (string) ( $ev['recurrence_id'] ?? '' );
			if ( '' !== $rid ) {
				$ev['uid']       = self::instance_uid( (string) $ev['uid'], $rid );
				$ev['is_series'] = true;
				$ev['series_id'] = 'ics-' . (string) ( $ev['series_id'] ?: self::base_uid( (string) $ev['uid'] ) );
				$overrides[ self::base_uid( (string) $ev['uid'] ) . '|' . $rid ] = $ev;
				continue;
			}
			$masters[] = $ev;
		}

		$out = [];
		foreach ( $masters as $ev ) {
			$rrule = trim( (string) ( $ev['rrule'] ?? '' ) );
			if ( '' === $rrule ) {
				$out[] = $ev;
				continue;
			}
			$first = Meta::to_datetime( (string) $ev['start'] );
			if ( ! $first ) {
				$out[] = $ev;
				continue;
			}
			$end_dt   = Meta::to_datetime( (string) ( $ev['end'] ?? '' ) );
			$duration = $end_dt ? max( 0, $end_dt->getTimestamp() - $first->getTimestamp() ) : 0;
			$uid      = (string) $ev['uid'];

			foreach ( self::rrule_starts( $first, $rrule, $from_ts, $to_ts ) as $start ) {
				$local = $start->format( 'Y-m-d H:i:s' );
				if ( self::is_excluded( $local, (array) ( $ev['exdates'] ?? [] ) ) ) {
					continue;
				}
				$key = $uid . '|' . $local;
				if ( isset( $overrides[ $key ] ) ) {
					$out[] = $overrides[ $key ];
					unset( $overrides[ $key ] );
					continue;
				}
				$inst                  = $ev;
				$inst['uid']           = self::instance_uid( $uid, $local );
				$inst['start']         = $local;
				$inst['end']           = $start->setTimestamp( $start->getTimestamp() + $duration )->format( 'Y-m-d H:i:s' );
				$inst['is_series']     = true;
				$inst['series_id']     = 'ics-' . $uid;
				$inst['rrule']         = '';
				$inst['exdates']       = [];
				$inst['recurrence_id'] = '';
				$out[]                 = $inst;
			}
		}

		// Overrides whose date the rule no longer produces (or whose master is
		// not in the feed) are still real events the source is showing.
		foreach ( $overrides as $ev ) {
			$ts = Meta::local_to_ts( (string) $ev['start'] );
			if ( $ts >= $from_ts && $ts <= $to_ts ) {
				$out[] = $ev;
			}
		}
		return $out;
	}

	private static function instance_uid( string $uid, string $local ): string {
		$dt = Meta::to_datetime( $local );
		return $uid . '@' . ( $dt ? $dt->format( 'Ymd\THis' ) : preg_replace( '/\D/', '', $local ) );
	}

	private static function base_uid( string $uid ): string {
		$at = strrpos( $uid, '@' );
		return false === $at ? $uid : substr( $uid, 0, $at );
	}

	private static function is_excluded( string $local, array $exdates ): bool {
		foreach ( $exdates as $ex ) {
			$x = (string) ( $ex['local'] ?? '' );
			if ( $x === $local ) {
				return true;
			}
			// A date-only EXDATE on a timed event removes the whole day.
			if ( ! empty( $ex['date_only'] ) && substr( $x, 0, 10 ) === substr( $local, 0, 10 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Occurrence starts for an RRULE, within the window, in order. The first
	 * date (DTSTART) always counts as an occurrence per RFC 5545, even when
	 * BYDAY would not have produced it.
	 *
	 * @return \DateTimeImmutable[]
	 */
	public static function rrule_starts( \DateTimeImmutable $first, string $rrule, int $from_ts, int $to_ts ): array {
		$p = [];
		foreach ( explode( ';', $rrule ) as $kv ) {
			[ $k, $v ] = array_pad( explode( '=', $kv, 2 ), 2, '' );
			$p[ strtoupper( trim( $k ) ) ] = strtoupper( trim( $v ) );
		}
		$freq = $p['FREQ'] ?? '';
		if ( ! in_array( $freq, [ 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ], true ) ) {
			return [ $first ];
		}
		$interval = max( 1, (int) ( $p['INTERVAL'] ?? 1 ) );
		$count    = max( 0, (int) ( $p['COUNT'] ?? 0 ) );
		$until    = self::until( $p['UNTIL'] ?? '', $first->getTimezone() );
		$byday    = '' !== ( $p['BYDAY'] ?? '' ) ? explode( ',', $p['BYDAY'] ) : [];
		$bymday   = (int) ( $p['BYMONTHDAY'] ?? 0 );
		$days     = [ 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 ];

		$out      = [];
		$produced = 0;
		for ( $i = 0; $i < 1000 && count( $out ) < self::MAX_INSTANCES; $i++ ) {
			$candidates = [];
			if ( 'DAILY' === $freq ) {
				$candidates[] = $first->modify( '+' . ( $i * $interval ) . ' days' );
			} elseif ( 'WEEKLY' === $freq ) {
				$base = $first->modify( '+' . ( $i * $interval ) . ' weeks' );
				$codes = [];
				foreach ( $byday as $d ) {
					if ( isset( $days[ $d ] ) ) {
						$codes[] = $days[ $d ];
					}
				}
				if ( ! $codes ) {
					$candidates[] = $base;
				} else {
					$dow = (int) $base->format( 'N' );
					foreach ( $codes as $n ) {
						$candidates[] = $base->modify( ( $n - $dow ) . ' days' );
					}
				}
			} elseif ( 'MONTHLY' === $freq ) {
				$month = $first->modify( 'first day of +' . ( $i * $interval ) . ' months' );
				$ord   = self::ordinal_byday( $byday, $days );
				if ( $ord ) {
					$c = self::nth_weekday_of_month( $month, $ord[0], $ord[1] );
					if ( $c ) {
						$candidates[] = $c;
					}
				} else {
					$day = $bymday > 0 ? $bymday : (int) $first->format( 'j' );
					if ( $day <= (int) $month->format( 't' ) ) {
						$candidates[] = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day );
					}
				}
			} else { // YEARLY
				$candidates[] = $first->modify( '+' . ( $i * $interval ) . ' years' );
			}

			usort( $candidates, static fn( $a, $b ) => $a <=> $b );
			foreach ( $candidates as $c ) {
				if ( $c < $first ) {
					continue; // earlier in DTSTART's own period than DTSTART itself
				}
				if ( $until && $c > $until ) {
					return $out;
				}
				$produced++;
				if ( $count && $produced > $count ) {
					return $out;
				}
				$ts = $c->getTimestamp();
				if ( $ts > $to_ts ) {
					return $out;
				}
				if ( $ts >= $from_ts ) {
					$out[] = $c;
				}
			}
		}
		return $out;
	}

	/** UNTIL as a DateTimeImmutable, or null. Date-only means end of that day. */
	private static function until( string $raw, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( '' === $raw ) {
			return null;
		}
		try {
			if ( preg_match( '/^\d{8}$/', $raw ) ) {
				return new \DateTimeImmutable( substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 ) . ' 23:59:59', $tz );
			}
			$z = 'Z' === substr( $raw, -1 );
			return new \DateTimeImmutable( rtrim( $raw, 'Z' ), $z ? new \DateTimeZone( 'UTC' ) : $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** "2TU" / "-1FR" → [ordinal, isoWeekday], or null when BYDAY has no ordinal. */
	private static function ordinal_byday( array $byday, array $days ): ?array {
		foreach ( $byday as $d ) {
			if ( preg_match( '/^([+-]?\d+)([A-Z]{2})$/', $d, $m ) && isset( $days[ $m[2] ] ) && 0 !== (int) $m[1] ) {
				return [ (int) $m[1], $days[ $m[2] ] ];
			}
		}
		return null;
	}

	/** The Nth (or Nth-from-last, negative) weekday of $month's month, keeping its time. */
	private static function nth_weekday_of_month( \DateTimeImmutable $month, int $nth, int $weekday ): ?\DateTimeImmutable {
		$y   = (int) $month->format( 'Y' );
		$m   = (int) $month->format( 'n' );
		$dim = (int) $month->format( 't' );
		if ( $nth > 0 ) {
			$offset = ( $weekday - (int) $month->format( 'N' ) + 7 ) % 7;
			$day    = 1 + $offset + ( $nth - 1 ) * 7;
		} else {
			$last   = $month->setDate( $y, $m, $dim );
			$offset = ( (int) $last->format( 'N' ) - $weekday + 7 ) % 7;
			$day    = $dim - $offset + ( $nth + 1 ) * 7;
		}
		if ( $day < 1 || $day > $dim ) {
			return null;
		}
		return $month->setDate( $y, $m, $day );
	}
}
