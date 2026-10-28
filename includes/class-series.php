<?php
/**
 * Recurring "series": materializes occurrences as flat posts that share a
 * `_gasf_series_id`, and provides series-level management (extend, apply to
 * future, delete the series). The first event doubles as the series anchor
 * (`master`); every member — including the anchor — is a real, displayable
 * event. No hidden template post. See docs/ARCHITECTURE.md §4.7.
 *
 * The same `_gasf_series_id` mechanism is reused for Facebook recurring
 * events in P5 (each FB occurrence is a flat member).
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Series {

	/** Guard so generated inserts don't recurse through save_post. */
	private static $generating = false;

	public static function is_generating(): bool {
		return self::$generating;
	}

	public function register_hooks(): void {
		add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
		add_action( 'admin_post_gasf_delete_series', [ $this, 'handle_delete_series' ] );
		add_action( 'admin_notices', [ $this, 'deleted_notice' ] );
		add_action( 'transition_post_status', [ $this, 'on_master_status' ], 10, 3 );
	}

	/**
	 * Occurrences follow the master between draft and published.
	 *
	 * They only ever inherited the master's status at creation
	 * (create_occurrence), so a series built as a draft and published later
	 * left every generated date a draft, and nothing on screen said so. Only
	 * the draft/pending/publish edges are mirrored, and only onto occurrences
	 * that have not started: trashing is the "Delete series" action's job, and
	 * unpublishing a master must not rewrite the history of dates already gone.
	 *
	 * @param string   $new  New post status.
	 * @param string   $old  Previous post status.
	 * @param \WP_Post $post The post.
	 */
	public function on_master_status( string $new, string $old, $post ): void {
		if ( $new === $old || self::$generating || ! $post instanceof \WP_Post || GASF_EVENTS_CPT !== $post->post_type ) {
			return;
		}
		$mirrored = [ 'publish', 'draft', 'pending' ];
		if ( ! in_array( $new, $mirrored, true ) || ! in_array( $old, array_merge( $mirrored, [ 'auto-draft', 'new', 'future' ] ), true ) ) {
			return;
		}
		if ( 'master' !== (string) get_post_meta( $post->ID, Meta::SERIES_ROLE, true ) ) {
			return;
		}
		$series_id = (string) get_post_meta( $post->ID, Meta::SERIES_ID, true );
		$now       = time();
		self::$generating = true;
		foreach ( self::members( $series_id, 'occurrence' ) as $occ_id ) {
			if ( get_post_status( $occ_id ) === $new || (int) get_post_meta( $occ_id, Meta::START_TS, true ) < $now ) {
				continue;
			}
			wp_update_post( [ 'ID' => $occ_id, 'post_status' => $new ] );
		}
		self::$generating = false;
	}

	public function deleted_notice(): void {
		if ( empty( $_GET['gasf_series_deleted'] ) ) {
			return;
		}
		$n = (int) $_GET['gasf_series_deleted'];
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %d: number of events moved to trash. */ _n( '%d event in the series moved to Trash.', '%d events in the series moved to Trash.', $n, 'gasf-events' ), $n ) )
		);
	}

	/* ---- Generation --------------------------------------------------- */

	/**
	 * Ensure the master is anchored and its occurrences exist. Called from
	 * Meta_Box::save after the master's own fields are stored.
	 *
	 * @return array{created:int,updated:int}
	 */
	public static function sync_from_master( int $master_id, string $freq, string $until, int $count, bool $apply_future ): array {
		$summary = [ 'created' => 0, 'updated' => 0 ];
		$master  = get_post( $master_id );
		if ( ! $master || GASF_EVENTS_CPT !== $master->post_type ) {
			return $summary;
		}
		$start = self::dt( (string) get_post_meta( $master_id, Meta::START, true ) );
		if ( ! $start || ! in_array( $freq, [ 'weekly', 'biweekly', 'monthly' ], true ) ) {
			return $summary;
		}

		// Anchor the master.
		$series_id = (string) get_post_meta( $master_id, Meta::SERIES_ID, true );
		if ( '' === $series_id ) {
			$series_id = 's' . $master_id . '-' . substr( md5( $master_id . $start->format( 'U' ) ), 0, 6 );
			update_post_meta( $master_id, Meta::SERIES_ID, $series_id );
		}
		update_post_meta( $master_id, Meta::SERIES_ROLE, 'master' );

		$duration = max( 0, ( self::dt( (string) get_post_meta( $master_id, Meta::END, true ) )?->getTimestamp() ?? $start->getTimestamp() ) - $start->getTimestamp() );

		$dates = Recurrence::after_first( $start, $freq, $until, $count );

		// Index existing occurrences by their start date string.
		$existing = [];
		foreach ( self::members( $series_id, 'occurrence' ) as $occ_id ) {
			$existing[ substr( (string) get_post_meta( $occ_id, Meta::START, true ), 0, 10 ) ] = $occ_id;
		}

		self::$generating = true;
		foreach ( $dates as $d ) {
			$key = $d->format( 'Y-m-d' );
			if ( isset( $existing[ $key ] ) ) {
				if ( $apply_future && $d->getTimestamp() >= time() ) {
					self::apply_master_to_occurrence( $master, $existing[ $key ], $d, $duration );
					$summary['updated']++;
				}
				continue;
			}
			self::create_occurrence( $master, $series_id, $d, $duration );
			$summary['created']++;
		}
		self::$generating = false;

		return $summary;
	}

	private static function create_occurrence( \WP_Post $master, string $series_id, \DateTimeImmutable $start, int $duration ): int {
		$occ_id = wp_insert_post( [
			'post_type'    => GASF_EVENTS_CPT,
			'post_status'  => $master->post_status,
			'post_title'   => $master->post_title,
			'post_content' => $master->post_content,
			'post_excerpt' => $master->post_excerpt,
			'post_author'  => $master->post_author,
		], true );
		if ( is_wp_error( $occ_id ) || ! $occ_id ) {
			return 0;
		}
		update_post_meta( $occ_id, Meta::SERIES_ID, $series_id );
		update_post_meta( $occ_id, Meta::SERIES_ROLE, 'occurrence' );
		self::write_dates( $occ_id, $start, $duration );
		self::copy_content_meta( $master->ID, $occ_id );
		self::copy_thumbnail( $master->ID, $occ_id );
		return (int) $occ_id;
	}

	private static function apply_master_to_occurrence( \WP_Post $master, int $occ_id, \DateTimeImmutable $date, int $duration ): void {
		// Keep the occurrence's DATE, adopt the master's time-of-day + content.
		$start = self::dt( (string) get_post_meta( $master->ID, Meta::START, true ) );
		$time  = $start ? $start->format( 'H:i:s' ) : '00:00:00';
		try {
			$occ_start = new \DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $time, wp_timezone() );
		} catch ( \Exception $e ) {
			$occ_start = $date;
		}
		wp_update_post( [
			'ID'           => $occ_id,
			'post_title'   => $master->post_title,
			'post_content' => $master->post_content,
			// "Apply to future" means the master's state too, not just its text.
			'post_status'  => $master->post_status,
		] );
		self::write_dates( $occ_id, $occ_start, $duration );
		self::copy_content_meta( $master->ID, $occ_id );
		self::copy_thumbnail( $master->ID, $occ_id );
	}

	private static function write_dates( int $post_id, \DateTimeImmutable $start, int $duration ): void {
		$end = $start->setTimestamp( $start->getTimestamp() + $duration );
		update_post_meta( $post_id, Meta::START, $start->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, Meta::END, $end->format( 'Y-m-d H:i:s' ) );
		Meta::recompute_timestamps( $post_id );
	}

	/** Copy the editable, non-date fields from master to a member. */
	private static function copy_content_meta( int $from, int $to ): void {
		$keys = [
			Meta::ALL_DAY, Meta::HIDE_TIME, Meta::HIDE_END, Meta::STATUS, Meta::STATUS_REASON,
			Meta::ONLINE_LINK, Meta::MORE_INFO_URL, Meta::MORE_INFO_TITLE, Meta::COST,
			Meta::VENUE_OVERRIDE, Meta::ORGANIZER_OVERRIDE, Meta::SOURCE,
			Meta::FEATURED, Meta::PRIORITY, Meta::SHORT_TITLE, Meta::SIGN_TEXT,
		];
		foreach ( $keys as $k ) {
			$v = get_post_meta( $from, $k, true );
			if ( '' === $v || null === $v || [] === $v ) {
				delete_post_meta( $to, $k );
			} else {
				update_post_meta( $to, $k, $v );
			}
		}
		if ( '' === (string) get_post_meta( $to, Meta::SOURCE, true ) ) {
			update_post_meta( $to, Meta::SOURCE, 'manual' );
		}
	}

	private static function copy_thumbnail( int $from, int $to ): void {
		$thumb = get_post_thumbnail_id( $from );
		if ( $thumb ) {
			set_post_thumbnail( $to, $thumb );
		} else {
			delete_post_thumbnail( $to );
		}
	}

	/* ---- Queries ------------------------------------------------------ */

	/** Post IDs in a series, optionally filtered by role. */
	public static function members( string $series_id, string $role = '' ): array {
		if ( '' === $series_id ) {
			return [];
		}
		$meta = [ [ 'key' => Meta::SERIES_ID, 'value' => $series_id ] ];
		if ( '' !== $role ) {
			$meta[] = [ 'key' => Meta::SERIES_ROLE, 'value' => $role ];
		}
		return get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'numberposts'      => -1,
			'fields'           => 'ids',
			'meta_query'       => $meta,
			'suppress_filters' => true,
		] );
	}

	public static function count( string $series_id ): int {
		return count( self::members( $series_id ) );
	}

	/* ---- Deletion ----------------------------------------------------- */

	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( GASF_EVENTS_CPT !== $post->post_type ) {
			return $actions;
		}
		$series_id = (string) get_post_meta( $post->ID, Meta::SERIES_ID, true );
		if ( '' === $series_id ) {
			return $actions;
		}
		$n = self::count( $series_id );
		if ( $n < 2 ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=gasf_delete_series&series=' . rawurlencode( $series_id ) ),
			'gasf_delete_series_' . $series_id
		);
		$actions['gasf_delete_series'] = sprintf(
			'<a href="%s" style="color:#b32d2e;" onclick="return confirm(%s);">%s</a>',
			esc_url( $url ),
			esc_js( sprintf( /* translators: %d: number of events. */ __( 'Delete ALL %d events in this series?', 'gasf-events' ), $n ) ),
			esc_html( sprintf( /* translators: %d: number of events. */ __( 'Delete series (%d)', 'gasf-events' ), $n ) )
		);
		return $actions;
	}

	public function handle_delete_series(): void {
		$series_id = isset( $_GET['series'] ) ? sanitize_text_field( wp_unslash( $_GET['series'] ) ) : '';
		if ( '' === $series_id || ! current_user_can( 'delete_gasf_events' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( 'gasf_delete_series_' . $series_id );

		$deleted = 0;
		foreach ( self::members( $series_id ) as $id ) {
			if ( wp_trash_post( $id ) ) {
				$deleted++;
			}
		}
		wp_safe_redirect( add_query_arg(
			[ 'post_type' => GASF_EVENTS_CPT, 'gasf_series_deleted' => $deleted ],
			admin_url( 'edit.php' )
		) );
		exit;
	}

	/* ---- Helpers ------------------------------------------------------ */

	private static function dt( string $local ): ?\DateTimeImmutable {
		return Meta::to_datetime( $local );
	}
}
