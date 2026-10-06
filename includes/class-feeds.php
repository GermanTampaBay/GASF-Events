<?php
/**
 * Feeds — the multi-feed ingest/route engine. Each feed has a TYPE
 * (facebook | ics) and one or more DESTINATIONS (GASF Calendar and/or Google
 * Calendar). One 15-min cron runs every enabled feed: fetch → upsert into the
 * GASF Calendar via Event_Ingest, and/or push to Google via Google_Calendar.
 *
 * Absorbs both the P5 Facebook importer and the legacy "Calendar Sync" MU
 * module into one home. Master-gated OFF by default. See ARCHITECTURE.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Feeds {

	const OPT_ENABLE   = 'gasf_events_enable_sync'; // master gate (default off)
	const OPT_FEEDS    = 'gasf_events_feeds';       // [ feed configs ]
	const OPT_GCAL     = 'gasf_events_gcal';        // { calendar_id }
	const OPT_LAST_RUN = 'gasf_events_last_run';
	const OPT_LOG      = 'gasf_events_sync_log';
	const CRON_HOOK    = 'gasf_events_sync';
	const SCHEDULE     = 'gasf_events_30min'; // was 15: the host rate-limits the whole site (0.29.1)
	const LOCK         = 'gasf_events_sync_lock';

	public function register_hooks(): void {
		add_filter( 'cron_schedules', [ $this, 'schedule' ] );
		add_action( 'init', [ $this, 'sync_cron_state' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_cron' ] );
	}

	public function schedule( array $s ): array {
		$s[ self::SCHEDULE ] = [ 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __( 'Every 30 minutes (GASF)', 'gasf-events' ) ];
		return $s;
	}

	public function sync_cron_state(): void {
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );
		// An event left on an older schedule counts as scheduled and would
		// keep its old cadence forever, so move it.
		if ( $scheduled && self::SCHEDULE !== wp_get_schedule( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$scheduled = false;
		}
		if ( self::enabled() && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
		} elseif ( ! self::enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public function run_cron(): void {
		if ( self::enabled() ) {
			self::run( false );
		}
	}

	/* ---- config ------------------------------------------------------- */

	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLE, false );
	}

	public static function feeds(): array {
		return array_values( (array) get_option( self::OPT_FEEDS, [] ) );
	}

	public static function gcal(): array {
		return wp_parse_args( (array) get_option( self::OPT_GCAL, [] ), [ 'calendar_id' => '' ] );
	}

	/**
	 * Apply a feed's title filter (case-insensitive CONTAINS). Shared by the
	 * sync run and the passthrough .ics endpoint so they can never drift.
	 */
	public static function apply_filter( array $feed, array $events ): array {
		$filter = trim( (string) ( $feed['filter'] ?? '' ) );
		if ( '' === $filter ) {
			return $events;
		}
		$needle = mb_strtolower( $filter );
		return array_values( array_filter(
			$events,
			static fn( $ev ) => false !== mb_strpos( mb_strtolower( (string) ( $ev['title'] ?? '' ) ), $needle )
		) );
	}

	/**
	 * Fetch a feed and return its filtered + title-prefixed normalized events —
	 * the exact set the passthrough .ics endpoint re-emits (no calendar writes).
	 * @return array{events:array,error:string}
	 */
	public static function fetch_transformed( array $feed ): array {
		$fetch = self::fetch_feed( $feed );
		if ( '' !== $fetch['error'] ) {
			return $fetch;
		}
		$events = self::apply_filter( $feed, $fetch['events'] );
		$prefix = trim( (string) ( $feed['prefix'] ?? '' ) );
		if ( '' !== $prefix && '-' !== $prefix ) {
			foreach ( $events as &$ev ) {
				$ev['title'] = $prefix . ' ' . (string) ( $ev['title'] ?? '' );
			}
			unset( $ev );
		}
		return [ 'events' => $events, 'error' => '' ];
	}

	/** Find the feed owning a passthrough-.ics token (constant-time compare). */
	public static function find_by_ics_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		foreach ( self::feeds() as $f ) {
			if ( ! empty( $f['dest_ics'] ) && '' !== (string) ( $f['ics_token'] ?? '' )
				&& hash_equals( (string) $f['ics_token'], $token ) ) {
				return $f;
			}
		}
		return null;
	}

	/** Public subscribe URL for a feed's passthrough .ics ('' if not enabled). */
	public static function ics_subscribe_url( array $feed ): string {
		if ( empty( $feed['dest_ics'] ) || empty( $feed['ics_token'] ) ) {
			return '';
		}
		return home_url( '/?gasf_feed_ics=' . rawurlencode( (string) $feed['ics_token'] ) );
	}

	public static function soonest_expiry_days(): ?int {
		$soonest = null;
		foreach ( self::feeds() as $f ) {
			$exp = (int) ( $f['expire_at'] ?? 0 );
			if ( $exp > 0 ) {
				$d = (int) floor( ( $exp - time() ) / DAY_IN_SECONDS );
				$soonest = ( null === $soonest ) ? $d : min( $soonest, $d );
			}
		}
		return $soonest;
	}

	/* ---- run ---------------------------------------------------------- */

	public static function run( bool $dry = false, string $only_feed = '' ): array {
		$stats = [ 'created' => 0, 'updated' => 0, 'drafted' => 0, 'skipped' => 0, 'merged' => 0, 'merges' => [], 'google' => [ 'inserted' => 0, 'updated' => 0, 'deleted' => 0 ], 'errors' => [], 'feeds' => [], 'dry' => $dry, 'ts' => time() ];

		// Atomic acquire (GET_LOCK) instead of the old check-then-set transient:
		// an overlapping cron tick + manual "Sync now" can no longer both pass.
		// The lock is connection-scoped, so it auto-releases at request end even
		// if a fetch/upsert throws (the old transient stayed stuck for 30 min).
		if ( ! $dry && ! Meta::acquire_lock( 'feeds', 0 ) ) {
			$stats['errors'][] = 'already running';
			return $stats;
		}
		$gcal = self::gcal();
		// Google writes are deferred to a second pass: several feeds share one
		// calendar, so duplicates have to be collapsed ACROSS feeds before any of
		// them is written. The GASF destination is unaffected and still writes
		// inline below.
		$google_queue = [];

		foreach ( self::feeds() as $feed ) {
			if ( empty( $feed['enabled'] ) || ( $only_feed && ( $feed['id'] ?? '' ) !== $only_feed ) ) {
				continue;
			}
			$fetch = self::fetch_feed( $feed );
			$fstat = [ 'label' => $feed['label'] ?? '?', 'type' => $feed['type'] ?? '?', 'fetched' => count( $fetch['events'] ), 'error' => $fetch['error'] ];
			if ( '' !== $fetch['error'] ) {
				$stats['errors'][] = ( $fstat['label'] . ': ' . $fetch['error'] );
				$stats['feeds'][]  = $fstat;
				continue; // never prune/delete when a fetch failed
			}

			// Optional per-feed title filter (case-insensitive CONTAINS) — e.g. the
			// Krampus site subscribes to the main calendar.ics with filter "Krampus".
			// Applied before both destinations, so prune_missing() stays consistent:
			// only filtered-in events ever carry this feed id.
			$filter = trim( (string) ( $feed['filter'] ?? '' ) );
			if ( '' !== $filter ) {
				$fetch['events']  = self::apply_filter( $feed, $fetch['events'] );
				$fstat['filter']  = $filter;
				$fstat['fetched'] = count( $fetch['events'] );
			}

			$source = ( 'ics' === ( $feed['type'] ?? '' ) ) ? 'ics' : 'facebook';

			// Optional per-feed title prefix, e.g. "[GTB]". For the GASF destination
			// '' and '-' both mean "no prefix"; for Google, '' keeps the default
			// "[label]" tag and '-' suppresses it (see Google_Calendar::sync_source).
			$prefix = trim( (string) ( $feed['prefix'] ?? '' ) );

			// Destination: GASF Calendar.
			if ( ! empty( $feed['dest_gasf'] ) ) {
				$seen = [];
				$link_source = ! empty( $feed['link_source'] );
				foreach ( $fetch['events'] as $ev ) {
					if ( '' !== $prefix && '-' !== $prefix ) {
						$ev['title'] = $prefix . ' ' . (string) ( $ev['title'] ?? '' );
					}
					$ev['link_source'] = $link_source;
					$action = Event_Ingest::upsert( $ev, $source, (string) $feed['id'], $dry );
					if ( isset( $stats[ $action ] ) ) {
						$stats[ $action ]++;
					}
					$seen[] = Event_Ingest::key( $source, (string) $ev['uid'] );
				}
				// Fail-safe: a successful-but-EMPTY fetch is inconclusive, never a
				// signal to draft the whole feed. Only prune when we saw events.
				if ( $fetch['events'] ) {
					$stats['drafted'] += Event_Ingest::prune_missing( (string) $feed['id'], $seen, $dry );
				}
			}

			// Destination: Google Calendar. Scope on the STABLE feed id (not the
			// editable label) so renaming a feed doesn't orphan + duplicate.
			// A feed may target its own calendar via 'gcal_id'; global is the default.
			// Queued rather than written now — see the merge pass below.
			$cal_id = trim( (string) ( $feed['gcal_id'] ?? '' ) ) ?: (string) $gcal['calendar_id'];
			if ( ! empty( $feed['dest_google'] ) && $cal_id && Google_Calendar::available() ) {
				$google_queue[] = [
					'feed'   => $feed,
					'events' => $fetch['events'],
					'cal_id' => $cal_id,
					'prefix' => $prefix,
					'sync'   => true,
				];
			}

			$stats['feeds'][] = $fstat;
		}

		/* ---- Collapse cross-feed duplicates, then write to Google -------- */
		if ( $google_queue ) {
			// A single-feed "Sync now" would otherwise re-insert the duplicates the
			// last full run collapsed, because the definitive feed is not in the queue
			// to match against. Pull it in for comparison only: 'sync' => false, so it
			// is never written and cannot trigger its own deletions.
			if ( $only_feed ) {
				$google_queue = self::add_merge_references( $google_queue, $gcal );
			}

			// Bucketed per calendar: feeds pointed at DIFFERENT calendars are not
			// duplicates of one another however alike their events look.
			$buckets = [];
			foreach ( $google_queue as $q ) {
				$buckets[ $q['cal_id'] ][] = $q;
			}
			foreach ( $buckets as $queue ) {
				// Merging is opt-in per calendar: it runs only when a feed writing to
				// THIS calendar is ticked Definitive. Falling back to config order
				// instead would mean the checkbox that names the behaviour does not
				// control it — unticking would look like "off" and still merge, just
				// with an arbitrary winner.
				$ranked    = self::rank_for_merge( $queue );
				$collapsed = self::has_definitive( $queue )
					? Event_Merge::collapse( $ranked )
					: [ 'groups' => $ranked, 'merges' => [] ];
				foreach ( $collapsed['merges'] as $m ) {
					$stats['merges'][] = $m;
				}
				foreach ( $collapsed['groups'] as $g ) {
					if ( empty( $g['sync'] ) ) {
						continue; // comparison-only reference feed
					}
					$gf  = $g['feed'];
					$res = Google_Calendar::sync_source(
						(string) $gf['id'],
						(string) ( $gf['label'] ?? $gf['id'] ),
						(string) $g['cal_id'],
						$g['events'],
						$dry,
						(string) $g['prefix'],
						trim( (string) ( $gf['gcal_color'] ?? '' ) )
					);
					if ( '' !== $res['error'] ) {
						$stats['errors'][] = ( (string) ( $gf['label'] ?? '?' ) . ' (google): ' . $res['error'] );
					} else {
						$stats['google']['inserted'] += $res['inserted'];
						$stats['google']['updated']  += $res['updated'];
						$stats['google']['deleted']  += $res['deleted'];
					}
				}
			}
			$stats['merged'] = count( $stats['merges'] );
		}

		if ( ! $dry ) {
			update_option( self::OPT_LAST_RUN, $stats, false );
			Meta::release_lock( 'feeds' );
			self::log( $stats );
			// One summary email per run for anything prune_missing() unpublished
			// (see class-alerts.php — silent auto-drafts hid a live event once).
			Alerts::flush();
		}
		return $stats;
	}

	/**
	 * Definitive feeds first, config order preserved within each tier.
	 *
	 * Priority is positional: whatever comes first keeps its own title, times
	 * and identity, and absorbs the rest. Ordering alone never triggers a
	 * merge — see has_definitive(); this only decides who wins once one is
	 * going to happen.
	 */
	private static function rank_for_merge( array $queue ): array {
		$primary = [];
		$rest    = [];
		foreach ( $queue as $q ) {
			if ( ! empty( $q['feed']['definitive'] ) ) {
				$primary[] = $q;
			} else {
				$rest[] = $q;
			}
		}
		return array_merge( $primary, $rest );
	}

	/** Is any feed writing to this calendar ticked Definitive? */
	private static function has_definitive( array $queue ): bool {
		foreach ( $queue as $q ) {
			if ( ! empty( $q['feed']['definitive'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add the definitive feeds sharing a calendar with something already in the
	 * queue, marked 'sync' => false. They exist only to be matched against, so a
	 * single-feed run collapses its duplicates the same way a full run does.
	 * A reference feed that will not fetch simply does not participate: failing
	 * to read it must never turn into writing duplicates.
	 */
	private static function add_merge_references( array $queue, array $gcal ): array {
		$cals = array_unique( array_column( $queue, 'cal_id' ) );
		$have = array_column( array_column( $queue, 'feed' ), 'id' );
		foreach ( self::feeds() as $feed ) {
			if ( empty( $feed['enabled'] ) || empty( $feed['definitive'] ) || empty( $feed['dest_google'] ) ) {
				continue;
			}
			if ( in_array( (string) ( $feed['id'] ?? '' ), $have, true ) ) {
				continue;
			}
			$cal = trim( (string) ( $feed['gcal_id'] ?? '' ) ) ?: (string) $gcal['calendar_id'];
			if ( ! in_array( $cal, $cals, true ) ) {
				continue;
			}
			$fetch = self::fetch_feed( $feed );
			if ( '' !== $fetch['error'] ) {
				continue;
			}
			if ( '' !== trim( (string) ( $feed['filter'] ?? '' ) ) ) {
				$fetch['events'] = self::apply_filter( $feed, $fetch['events'] );
			}
			$queue[] = [
				'feed'   => $feed,
				'events' => $fetch['events'],
				'cal_id' => $cal,
				'prefix' => trim( (string) ( $feed['prefix'] ?? '' ) ),
				'sync'   => false,
			];
		}
		return $queue;
	}

	/**
	 * Fetch one feed into a uniform "superset event" list consumable by both
	 * destinations. @return array{events:array,error:string}
	 */
	private static function fetch_feed( array $feed ): array {
		if ( 'ics' === ( $feed['type'] ?? '' ) ) {
			// reject_unsafe_urls blocks redirects to private/loopback hosts (SSRF) on
			// the admin-supplied feed URL.
			$resp = wp_remote_get( (string) ( $feed['url'] ?? '' ), [ 'timeout' => 30, 'reject_unsafe_urls' => true ] );
			if ( is_wp_error( $resp ) ) {
				return [ 'events' => [], 'error' => $resp->get_error_message() ];
			}
			if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
				return [ 'events' => [], 'error' => 'HTTP ' . wp_remote_retrieve_response_code( $resp ) ];
			}
			$events = [];
			foreach ( ICS_Parser::parse( wp_remote_retrieve_body( $resp ) ) as $rec ) {
				$norm = ICS_Parser::to_norm( $rec );
				if ( $norm ) {
					$norm['location'] = (string) ( $rec['location'] ?? '' );
					$norm['rrule']    = (string) ( $rec['rrule'] ?? '' );
					$events[]         = $norm;
				}
			}
			return [ 'events' => $events, 'error' => '' ];
		}

		// Facebook.
		$account = [
			'id'           => $feed['id'] ?? '',
			'label'        => $feed['label'] ?? '',
			'page_id'      => $feed['page_id'] ?? '',
			'access_token' => $feed['access_token'] ?? '',
		];
		$res = FB_Client::fetch_events( $account );
		if ( '' !== $res['error'] ) {
			return [ 'events' => [], 'error' => $res['error'] ];
		}
		$events = [];
		foreach ( $res['events'] as $fb_event ) {
			foreach ( $fb_event['occurrences'] as $occ ) {
				$events[] = [
					'uid'         => $occ['fb_id'],
					'title'       => $occ['name'],
					'description' => $occ['description'],
					'start'       => $occ['start'],
					'end'         => $occ['end'],
					'all_day'     => false,
					'status'      => $occ['is_canceled'] ? 'cancelled' : '',
					'cover_url'   => $occ['cover_url'],
					'cover_id'    => $occ['cover_id'],
					'is_series'   => ! empty( $occ['is_series'] ),
					'series_id'   => $occ['series_id'],
					'location'    => '',
					'rrule'       => '',
				];
			}
		}
		return [ 'events' => $events, 'error' => '' ];
	}

	/* ---- persistence helpers (used by the admin page) ----------------- */

	public static function save_feeds( array $feeds ): void {
		update_option( self::OPT_FEEDS, array_values( $feeds ), false );
	}

	public static function new_id(): string {
		return 'f' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	private static function log( array $stats ): void {
		$line = sprintf(
			'[%s] created=%d updated=%d drafted=%d skipped=%d | google +%d/~%d/-%d | errors=%d',
			gmdate( 'c', $stats['ts'] ), $stats['created'], $stats['updated'], $stats['drafted'], $stats['skipped'],
			$stats['google']['inserted'], $stats['google']['updated'], $stats['google']['deleted'], count( $stats['errors'] )
		);
		$log = (array) get_option( self::OPT_LOG, [] );
		array_unshift( $log, $line );
		update_option( self::OPT_LOG, array_slice( $log, 0, 50 ), false );
	}
}
