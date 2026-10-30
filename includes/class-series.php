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
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		foreach ( [ 'detach', 'reattach', 'end', 'trash_orphans' ] as $a ) {
			add_action( 'admin_post_gasf_series_' . $a, [ $this, 'handle_' . $a ] );
		}
	}

	/* ---- Hand edits ----------------------------------------------------- */

	/**
	 * The fields "apply to future" would overwrite on an occurrence, hashed.
	 * Meta_Box::save compares this before and after it writes, so an edit to
	 * one date (cancelling one Euchre night, moving it a day) is remembered as
	 * deliberate and the next series-wide change leaves that date alone.
	 */
	public static function fingerprint( int $post_id ): string {
		$keys = [
			Meta::START, Meta::END, Meta::ALL_DAY, Meta::STATUS, Meta::STATUS_REASON, Meta::ONLINE_LINK,
			Meta::MORE_INFO_URL, Meta::MORE_INFO_TITLE, Meta::COST, Meta::VENUE_OVERRIDE,
			Meta::FEATURED, Meta::PRIORITY, Meta::SHORT_TITLE, Meta::SIGN_TEXT,
		];
		$vals = [];
		foreach ( $keys as $k ) {
			$vals[ $k ] = get_post_meta( $post_id, $k, true );
		}
		return md5( (string) wp_json_encode( $vals ) );
	}

	/** Mark an occurrence hand-edited if its fingerprint changed since $before. */
	public static function note_edit( int $post_id, string $before ): void {
		if ( self::$generating || 'occurrence' !== (string) get_post_meta( $post_id, Meta::SERIES_ROLE, true ) ) {
			return;
		}
		if ( '' !== $before && $before !== self::fingerprint( $post_id ) ) {
			update_post_meta( $post_id, Meta::SERIES_CUSTOMIZED, 1 );
		}
	}

	/**
	 * Title, description or status changed on a manual occurrence by hand
	 * (classic editor, block editor or Quick Edit all come through here).
	 * Feed-sourced occurrences are skipped: their source rewrites them on
	 * every sync and there is no master whose changes they could be shielded from.
	 */
	public function on_post_updated( $post_id, $after, $before ): void {
		if ( self::$generating || ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || GASF_EVENTS_CPT !== $after->post_type ) {
			return;
		}
		if ( 'occurrence' !== (string) get_post_meta( $post_id, Meta::SERIES_ROLE, true ) || 'trash' === $after->post_status ) {
			return; // trashing is not an edit; a restored date should still follow the series
		}
		$event = Event::get( $after );
		if ( ! $event || $event->is_synced() ) {
			return;
		}
		if ( $after->post_title !== $before->post_title || $after->post_content !== $before->post_content || $after->post_status !== $before->post_status ) {
			update_post_meta( $post_id, Meta::SERIES_CUSTOMIZED, 1 );
		}
	}

	public static function is_customized( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, Meta::SERIES_CUSTOMIZED, true );
	}

	/** The series' master post id, or 0 (Facebook/ICS series have none). */
	public static function master_of( string $series_id ): int {
		$m = self::members( $series_id, 'master' );
		return $m ? (int) $m[0] : 0;
	}

	/* ---- Planning (pure) ---------------------------------------------- */

	/**
	 * What a master save should do to its series. Pure — no WordPress calls —
	 * so it is unit-tested directly (tests/unit/SeriesPlanTest.php).
	 *
	 * Occurrences are matched to the rule by SLOT: the date the rule gave them
	 * when they were created, which survives a hand move. Matching by the
	 * current start date (as before v0.38.0) meant a Euchre night moved from
	 * the 12th to the 13th no longer "occupied" the 12th, so the next master
	 * save quietly created a second Euchre night on the 12th.
	 *
	 * @param array<string,int> $rule    slot (Y-m-d) => that slot's start timestamp, as the rule now produces them
	 * @param array<int,array{slot:string,start_ts:int,customized:bool}> $members occurrence id => state
	 * @param int  $now
	 * @param bool $apply_future "Apply my changes to future occurrences" was ticked
	 * @return array{create:string[], apply:array<int,string>, orphans:int[], kept_custom:int[]}
	 *         create: slots with no occurrence yet; apply: occurrence id => slot to re-stamp;
	 *         orphans: future, untouched occurrences the rule no longer produces;
	 *         kept_custom: future hand-edited ones the rule no longer produces (left alone, reported)
	 */
	public static function plan( array $rule, array $members, int $now, bool $apply_future ): array {
		$out     = [ 'create' => [], 'apply' => [], 'orphans' => [], 'kept_custom' => [] ];
		$by_slot = [];
		ksort( $members ); // lowest id first: the original holds a slot, a later duplicate does not
		foreach ( $members as $id => $m ) {
			$slot = (string) ( $m['slot'] ?? '' );
			if ( isset( $rule[ $slot ] ) && ! isset( $by_slot[ $slot ] ) ) {
				$by_slot[ $slot ] = (int) $id;
				continue;
			}
			// Not (or no longer) one of the rule's dates — or a duplicate of one.
			if ( (int) ( $m['start_ts'] ?? 0 ) < $now ) {
				continue; // history stays as it happened
			}
			if ( ! empty( $m['customized'] ) ) {
				$out['kept_custom'][] = (int) $id;
			} else {
				$out['orphans'][] = (int) $id;
			}
		}
		foreach ( $rule as $slot => $ts ) {
			if ( ! isset( $by_slot[ $slot ] ) ) {
				$out['create'][] = (string) $slot;
				continue;
			}
			$id = $by_slot[ $slot ];
			if ( $apply_future && $ts >= $now && empty( $members[ $id ]['customized'] ) ) {
				$out['apply'][ $id ] = (string) $slot;
			}
		}
		return $out;
	}

	/** The rule's slots for a master, from its stored repeat settings. @return array<string,int> */
	public static function rule_slots( int $master_id ): array {
		$freq  = (string) get_post_meta( $master_id, Meta::REPEAT, true );
		$start = self::dt( (string) get_post_meta( $master_id, Meta::START, true ) );
		if ( ! $start || ! in_array( $freq, [ 'weekly', 'biweekly', 'monthly' ], true ) ) {
			return [];
		}
		$out = [];
		foreach ( Recurrence::after_first( $start, $freq, (string) get_post_meta( $master_id, Meta::REPEAT_UNTIL, true ), (int) get_post_meta( $master_id, Meta::REPEAT_COUNT, true ) ) as $d ) {
			$out[ $d->format( 'Y-m-d' ) ] = $d->getTimestamp();
		}
		return $out;
	}

	/** Occurrence states for plan(). @return array<int,array{slot:string,start_ts:int,customized:bool}> */
	private static function member_states( string $series_id ): array {
		$out = [];
		foreach ( self::members( $series_id, 'occurrence' ) as $id ) {
			$slot = (string) get_post_meta( $id, Meta::SERIES_SLOT, true );
			if ( '' === $slot ) {
				// Created before v0.38.0: its slot is the date it still has.
				$slot = substr( (string) get_post_meta( $id, Meta::START, true ), 0, 10 );
			}
			$out[ (int) $id ] = [
				'slot'       => $slot,
				'start_ts'   => (int) get_post_meta( $id, Meta::START_TS, true ),
				'customized' => self::is_customized( (int) $id ),
			];
		}
		return $out;
	}

	/**
	 * The current plan for a master, as the editor shows it. Nothing written.
	 * Empty when the master no longer repeats: turning the rule off leaves its
	 * dates where they are on purpose (use Delete series / End series).
	 */
	public static function current_plan( int $master_id ): array {
		$series_id = (string) get_post_meta( $master_id, Meta::SERIES_ID, true );
		$rule      = self::rule_slots( $master_id );
		if ( '' === $series_id || ! $rule ) {
			return [ 'create' => [], 'apply' => [], 'orphans' => [], 'kept_custom' => [] ];
		}
		return self::plan( $rule, self::member_states( $series_id ), time(), false );
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
		try {
			foreach ( self::members( $series_id, 'occurrence' ) as $occ_id ) {
				if ( get_post_status( $occ_id ) === $new || (int) get_post_meta( $occ_id, Meta::START_TS, true ) < $now ) {
					continue;
				}
				if ( self::is_customized( (int) $occ_id ) ) {
					continue; // a date somebody drafted or published by hand keeps that choice
				}
				wp_update_post( [ 'ID' => $occ_id, 'post_status' => $new ] );
			}
		} finally {
			self::$generating = false;
		}
	}

	public function deleted_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
		if ( ! empty( $_GET['gasf_series_deleted'] ) ) {
			$n = (int) $_GET['gasf_series_deleted'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %d: number of events moved to trash. */ _n( '%d event in the series moved to Trash.', '%d events in the series moved to Trash.', $n, 'gasf-events' ), $n ) )
			);
		}
		$code = isset( $_GET['gasf_series_msg'] ) ? sanitize_key( wp_unslash( $_GET['gasf_series_msg'] ) ) : '';
		$n    = isset( $_GET['gasf_series_n'] ) ? (int) $_GET['gasf_series_n'] : 0;
		// phpcs:enable
		$msgs = [
			'detached'   => __( 'This date is now a standalone event. Series changes and "Delete series" no longer touch it.', 'gasf-events' ),
			'reattached' => __( 'This date follows the series again: the next "Apply my changes to future occurrences" will overwrite it.', 'gasf-events' ),
			/* translators: %d: number of dates */
			'ended'      => sprintf( _n( 'Series ended here. %d later date moved to Trash, and the repeat rule now stops at this date.', 'Series ended here. %d later dates moved to Trash, and the repeat rule now stops at this date.', $n, 'gasf-events' ), $n ),
			/* translators: %d: number of dates */
			'orphans'    => sprintf( _n( '%d date that no longer fits the repeat rule moved to Trash.', '%d dates that no longer fit the repeat rule moved to Trash.', $n, 'gasf-events' ), $n ),
		];
		if ( isset( $msgs[ $code ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msgs[ $code ] ) );
		}
	}

	/* ---- Series actions (editor buttons) ------------------------------- */

	/** A signed admin-post link for one of the actions below. */
	public static function action_url( string $action, int $post_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=gasf_series_' . $action . '&post=' . $post_id ),
			'gasf_series_' . $action . '_' . $post_id
		);
	}

	/** Verify the request and return the post id; dies on anything wrong. */
	private static function guard( string $action, string $cap = 'edit_post' ): int {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $id || GASF_EVENTS_CPT !== get_post_type( $id ) || ! current_user_can( $cap, $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( 'gasf_series_' . $action . '_' . $id );
		return $id;
	}

	private static function back_to( int $post_id, string $code, int $n = 0 ): void {
		wp_safe_redirect( add_query_arg( [ 'gasf_series_msg' => $code, 'gasf_series_n' => $n ], get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	/** Make one date a standalone event: it leaves the series entirely. */
	public function handle_detach(): void {
		$id = self::guard( 'detach' );
		delete_post_meta( $id, Meta::SERIES_ID );
		delete_post_meta( $id, Meta::SERIES_SLOT );
		delete_post_meta( $id, Meta::SERIES_CUSTOMIZED );
		update_post_meta( $id, Meta::SERIES_ROLE, 'single' );
		self::back_to( $id, 'detached' );
	}

	/** Forget the hand edit: the date follows "apply to future" again. */
	public function handle_reattach(): void {
		$id = self::guard( 'reattach' );
		delete_post_meta( $id, Meta::SERIES_CUSTOMIZED );
		self::back_to( $id, 'reattached' );
	}

	/**
	 * End the series after this date: every LATER date goes to Trash and the
	 * master's rule is cut to stop here, so the next master save cannot
	 * re-create them. Trash, not delete — a wrong click is a Restore away.
	 * Only for series built in the editor; a Facebook/ICS series is rebuilt
	 * from its source on every sync, so ending it here would not stick.
	 */
	public function handle_end(): void {
		$id        = self::guard( 'end', 'delete_post' );
		$series_id = (string) get_post_meta( $id, Meta::SERIES_ID, true );
		$master    = self::master_of( $series_id );
		if ( ! $master ) {
			wp_die( esc_html__( 'This series comes from a feed. End it at its source (Facebook or the calendar it is imported from).', 'gasf-events' ) );
		}
		$cut = (int) get_post_meta( $id, Meta::START_TS, true );
		$n   = 0;
		foreach ( self::members( $series_id, 'occurrence' ) as $occ ) {
			if ( (int) get_post_meta( $occ, Meta::START_TS, true ) > $cut && wp_trash_post( $occ ) ) {
				$n++;
			}
		}
		$stop = substr( (string) get_post_meta( $id, Meta::START, true ), 0, 10 );
		if ( '' !== $stop ) {
			update_post_meta( $master, Meta::REPEAT_UNTIL, $stop );
			delete_post_meta( $master, Meta::REPEAT_COUNT );
		}
		self::back_to( $id, 'ended', $n );
	}

	/**
	 * Trash the future, untouched dates the master's rule no longer produces
	 * (after the rule was shortened, or the master moved to another weekday).
	 * The list is recomputed here rather than taken from the link, so it is
	 * exactly what the rule says now. Hand-edited dates are never included.
	 */
	public function handle_trash_orphans(): void {
		$id = self::guard( 'trash_orphans', 'delete_post' );
		if ( 'master' !== (string) get_post_meta( $id, Meta::SERIES_ROLE, true ) ) {
			wp_die( esc_html__( 'Only the first event of a series can tidy it.', 'gasf-events' ) );
		}
		$n = 0;
		foreach ( self::current_plan( $id )['orphans'] as $occ ) {
			if ( wp_trash_post( $occ ) ) {
				$n++;
			}
		}
		self::back_to( $id, 'orphans', $n );
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

		$dates = [];
		foreach ( Recurrence::after_first( $start, $freq, $until, $count ) as $d ) {
			$dates[ $d->format( 'Y-m-d' ) ] = $d;
		}
		$plan = self::plan( array_map( static fn( $d ) => $d->getTimestamp(), $dates ), self::member_states( $series_id ), time(), $apply_future );

		self::$generating = true;
		try {
			foreach ( $plan['apply'] as $occ_id => $slot ) {
				self::apply_master_to_occurrence( $master, (int) $occ_id, $dates[ $slot ], $duration );
				$summary['updated']++;
			}
			foreach ( $plan['create'] as $slot ) {
				self::create_occurrence( $master, $series_id, $dates[ $slot ], $duration );
				$summary['created']++;
			}
		} finally {
			// A throw mid-loop must not leave every later save_post thinking it
			// is inside a generation pass.
			self::$generating = false;
		}
		// Orphans are not touched here: the editor lists them with a one-click
		// "Move to Trash" (current_plan()), so a rule change can never delete a
		// date without somebody seeing which dates go.
		$summary['orphans'] = count( $plan['orphans'] );

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
		update_post_meta( $occ_id, Meta::SERIES_SLOT, $start->format( 'Y-m-d' ) );
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
