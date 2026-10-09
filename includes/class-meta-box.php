<?php
/**
 * The "Event Details" meta box — the maintainer-facing add/edit form.
 * Deliberately one simple panel (not MEC's wall of tabs). See §4.5.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Meta_Box {

	const NONCE = 'gasf_events_details_nonce';

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ $this, 'save' ], 10, 2 );
	}

	public function add(): void {
		add_meta_box(
			'gasf_event_details',
			__( 'Event Details', 'gasf-events' ),
			[ $this, 'render' ],
			GASF_EVENTS_CPT,
			'normal',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'gasf_events_save_' . $post->ID, self::NONCE );

		$g = static fn( $k ) => get_post_meta( $post->ID, $k, true );

		$start = (string) $g( Meta::START );
		$end   = (string) $g( Meta::END );
		// Split stored 'Y-m-d H:i:s' into date + time inputs.
		$start_date = $start ? substr( $start, 0, 10 ) : '';
		$start_time = $start ? substr( $start, 11, 5 ) : '';
		$end_date   = $end ? substr( $end, 0, 10 ) : '';
		$end_time   = $end ? substr( $end, 11, 5 ) : '';

		$all_day   = (bool) $g( Meta::ALL_DAY );
		$status    = (string) $g( Meta::STATUS );
		$reason    = (string) $g( Meta::STATUS_REASON );
		$online    = (string) $g( Meta::ONLINE_LINK );
		$mi_url    = (string) $g( Meta::MORE_INFO_URL );
		$mi_title  = (string) $g( Meta::MORE_INFO_TITLE );
		$cost      = (string) $g( Meta::COST );
		$source    = (string) $g( Meta::SOURCE ) ?: 'manual';
		$event_obj = Event::get( $post->ID );
		$synced    = $event_obj && $event_obj->is_synced();
		$src_label = 'facebook' === $source ? __( 'Facebook', 'gasf-events' ) : ( 'ics' === $source ? __( 'iCal feed', 'gasf-events' ) : __( 'Manual', 'gasf-events' ) );
		$locked    = (bool) $g( Meta::SYNC_LOCKED );
		$venue_ov  = (array) ( $g( Meta::VENUE_OVERRIDE ) ?: [] );

		$role      = (string) $g( Meta::SERIES_ROLE ) ?: 'single';
		$repeat    = (string) $g( Meta::REPEAT );
		$rep_until = (string) $g( Meta::REPEAT_UNTIL );
		$rep_count = (int) $g( Meta::REPEAT_COUNT );
		$series_id = (string) $g( Meta::SERIES_ID );
		$series_n  = $series_id ? Series::count( $series_id ) : 0;
		?>
		<style>
			.gasf-mb label.row { display:block; font-weight:600; margin:14px 0 4px; }
			.gasf-mb .inline { display:inline-block; margin-right:18px; }
			.gasf-mb input[type=date],.gasf-mb input[type=time]{ font-size:14px; }
			.gasf-mb .badge{ display:inline-block;padding:2px 8px;border-radius:3px;background:#e5e5e5;font-size:12px; }
			.gasf-mb .badge.fb{ background:#1877f2;color:#fff; }
			.gasf-mb fieldset.adv{ border:1px solid #e0e0e0;padding:8px 12px;margin-top:14px; }
		</style>
		<div class="gasf-mb">
			<p>
				<span class="inline"><label><input type="checkbox" name="gasf_all_day" value="1" id="gasf_all_day" <?php checked( $all_day ); ?>> <?php esc_html_e( 'All-day event', 'gasf-events' ); ?></label></span>
			</p>

			<div class="gasf-times">
				<span class="inline">
					<label class="row"><?php esc_html_e( 'Starts', 'gasf-events' ); ?></label>
					<input type="date" name="gasf_start_date" value="<?php echo esc_attr( $start_date ); ?>" required>
					<input type="time" class="gasf-time" name="gasf_start_time" value="<?php echo esc_attr( $start_time ); ?>">
				</span>
				<span class="inline">
					<label class="row"><?php esc_html_e( 'Ends', 'gasf-events' ); ?></label>
					<input type="date" name="gasf_end_date" value="<?php echo esc_attr( $end_date ); ?>">
					<input type="time" class="gasf-time" name="gasf_end_time" value="<?php echo esc_attr( $end_time ); ?>">
				</span>
			</div>

			<label class="row" for="gasf_status"><?php esc_html_e( 'Status', 'gasf-events' ); ?></label>
			<select name="gasf_status" id="gasf_status">
				<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'Scheduled', 'gasf-events' ); ?></option>
				<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'gasf-events' ); ?></option>
				<option value="postponed" <?php selected( $status, 'postponed' ); ?>><?php esc_html_e( 'Postponed', 'gasf-events' ); ?></option>
				<option value="sold_out" <?php selected( $status, 'sold_out' ); ?>><?php esc_html_e( 'Sold out', 'gasf-events' ); ?></option>
				<option value="online_only" <?php selected( $status, 'online_only' ); ?>><?php esc_html_e( 'Online only', 'gasf-events' ); ?></option>
			</select>
			<input type="text" name="gasf_status_reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'gasf-events' ); ?>" value="<?php echo esc_attr( $reason ); ?>" style="width:40%;">
			<input type="url" name="gasf_online_link" placeholder="<?php esc_attr_e( 'Online link (for online-only)', 'gasf-events' ); ?>" value="<?php echo esc_attr( $online ); ?>" style="width:40%;">

			<label class="row"><?php esc_html_e( 'More-info link', 'gasf-events' ); ?></label>
			<input type="url" name="gasf_more_info_url" placeholder="https://…" value="<?php echo esc_attr( $mi_url ); ?>" style="width:50%;">
			<input type="text" name="gasf_more_info_title" placeholder="<?php esc_attr_e( 'Link text', 'gasf-events' ); ?>" value="<?php echo esc_attr( $mi_title ); ?>" style="width:30%;">

			<label class="row"><?php esc_html_e( 'Cost (USD, leave blank if free)', 'gasf-events' ); ?></label>
			<input type="number" step="0.01" min="0" name="gasf_cost" value="<?php echo esc_attr( $cost ); ?>" style="width:120px;">

			<fieldset class="adv">
				<label class="row" style="margin-top:0;"><?php esc_html_e( 'Override venue (optional — leave blank to use the club)', 'gasf-events' ); ?></label>
				<?php $gasf_saved_venues = Venues::all(); ?>
				<?php if ( $gasf_saved_venues ) : ?>
					<select id="gasf-venue-pick" style="width:48%;margin-bottom:6px;">
						<option value=""><?php esc_html_e( '— Reuse a saved venue —', 'gasf-events' ); ?></option>
						<?php foreach ( $gasf_saved_venues as $gasf_v ) : ?>
							<option value="<?php echo esc_attr( (string) wp_json_encode( $gasf_v ) ); ?>"><?php
								echo esc_html( $gasf_v['name'] . ( '' !== $gasf_v['city'] ? ' — ' . $gasf_v['city'] : '' ) );
							?></option>
						<?php endforeach; ?>
					</select>
					<script>
					( function () {
						var pick = document.getElementById( 'gasf-venue-pick' );
						if ( ! pick ) { return; }
						pick.addEventListener( 'change', function () {
							if ( ! this.value ) { return; }
							var v;
							try { v = JSON.parse( this.value ); } catch ( e ) { return; }
							[ 'name', 'street', 'city', 'state', 'zip' ].forEach( function ( f ) {
								var el = document.querySelector( '[name="gasf_venue[' + f + ']"]' );
								if ( el ) { el.value = v[ f ] || ''; }
							} );
							// Back to the placeholder: the dropdown fills the fields and then
							// stops mattering — the text inputs are what actually get saved,
							// and leaving a name selected implies otherwise.
							this.selectedIndex = 0;
						} );
					} )();
					</script>
				<?php endif; ?>
				<input type="text" name="gasf_venue[name]" placeholder="<?php esc_attr_e( 'Venue name', 'gasf-events' ); ?>" value="<?php echo esc_attr( $venue_ov['name'] ?? '' ); ?>" style="width:48%;">
				<input type="text" name="gasf_venue[street]" placeholder="<?php esc_attr_e( 'Street', 'gasf-events' ); ?>" value="<?php echo esc_attr( $venue_ov['street'] ?? '' ); ?>" style="width:48%;">
				<input type="text" name="gasf_venue[city]" placeholder="<?php esc_attr_e( 'City', 'gasf-events' ); ?>" value="<?php echo esc_attr( $venue_ov['city'] ?? '' ); ?>">
				<input type="text" name="gasf_venue[state]" placeholder="<?php esc_attr_e( 'State', 'gasf-events' ); ?>" size="4" value="<?php echo esc_attr( $venue_ov['state'] ?? '' ); ?>">
				<input type="text" name="gasf_venue[zip]" placeholder="<?php esc_attr_e( 'ZIP', 'gasf-events' ); ?>" size="10" value="<?php echo esc_attr( $venue_ov['zip'] ?? '' ); ?>">
			</fieldset>

			<?php if ( 'occurrence' === $role ) : ?>
				<fieldset class="adv">
					<label class="row" style="margin-top:0;"><?php esc_html_e( 'Recurring event', 'gasf-events' ); ?></label>
					<p style="margin:4px 0;">
						<?php esc_html_e( 'This is one date in a recurring series. Editing here changes only this occurrence.', 'gasf-events' ); ?>
						<?php
						$siblings = $series_id ? Series::members( $series_id, 'master' ) : [];
						if ( $siblings ) :
							?>
							<a href="<?php echo esc_url( get_edit_post_link( $siblings[0] ) ); ?>"><?php esc_html_e( 'Edit the series', 'gasf-events' ); ?></a>
						<?php endif; ?>
					</p>
				</fieldset>
			<?php else : ?>
				<fieldset class="adv">
					<label class="row" style="margin-top:0;" for="gasf_repeat"><?php esc_html_e( 'Repeats', 'gasf-events' ); ?></label>
					<select name="gasf_repeat" id="gasf_repeat">
						<option value="" <?php selected( $repeat, '' ); ?>><?php esc_html_e( 'Does not repeat', 'gasf-events' ); ?></option>
						<option value="weekly" <?php selected( $repeat, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'gasf-events' ); ?></option>
						<option value="biweekly" <?php selected( $repeat, 'biweekly' ); ?>><?php esc_html_e( 'Every 2 weeks', 'gasf-events' ); ?></option>
						<option value="monthly" <?php selected( $repeat, 'monthly' ); ?>><?php esc_html_e( 'Monthly (same weekday)', 'gasf-events' ); ?></option>
					</select>
					<span class="gasf-rep-end" style="<?php echo $repeat ? '' : 'display:none;'; ?>">
						&nbsp;<?php esc_html_e( 'until', 'gasf-events' ); ?>
						<input type="date" name="gasf_repeat_until" value="<?php echo esc_attr( $rep_until ); ?>">
						<?php esc_html_e( 'or', 'gasf-events' ); ?>
						<input type="number" min="2" max="<?php echo esc_attr( Meta::REPEAT_MAX ); ?>" name="gasf_repeat_count" value="<?php echo esc_attr( $rep_count ?: '' ); ?>" placeholder="<?php esc_attr_e( '# times', 'gasf-events' ); ?>" style="width:90px;">
					</span>
					<?php if ( 'master' === $role && $series_n > 1 ) : ?>
						<p style="margin:8px 0 0;">
							<label><input type="checkbox" name="gasf_apply_future" value="1"> <?php esc_html_e( 'Apply my changes to future occurrences in this series', 'gasf-events' ); ?></label>
							<span style="color:#666;">(<?php echo esc_html( sprintf( /* translators: %d count */ __( '%d events in series', 'gasf-events' ), $series_n ) ); ?>)</span>
						</p>
					<?php else : ?>
						<p class="description" style="margin:6px 0 0;"><?php esc_html_e( 'Saving will create the individual events for each date.', 'gasf-events' ); ?></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<p style="margin-top:16px;">
				<?php esc_html_e( 'Source:', 'gasf-events' ); ?>
				<span class="badge <?php echo 'facebook' === $source ? 'fb' : ''; ?>"><?php echo esc_html( $src_label ); ?></span>
				<?php if ( $synced ) : ?>
					<label style="margin-left:14px;"><input type="checkbox" name="gasf_sync_locked" value="1" <?php checked( $locked ); ?>> <?php esc_html_e( "Pin — don't let the feed sync overwrite this event", 'gasf-events' ); ?></label>
				<?php endif; ?>
			</p>
		</div>
		<script>
		( function () {
			var cb = document.getElementById( 'gasf_all_day' );
			function toggle() {
				document.querySelectorAll( '.gasf-time' ).forEach( function ( el ) {
					el.style.display = cb.checked ? 'none' : '';
				} );
			}
			if ( cb ) { cb.addEventListener( 'change', toggle ); toggle(); }

			var rep = document.getElementById( 'gasf_repeat' );
			if ( rep ) {
				rep.addEventListener( 'change', function () {
					var end = document.querySelector( '.gasf-rep-end' );
					if ( end ) { end.style.display = rep.value ? '' : 'none'; }
				} );
			}
		} )();
		</script>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), 'gasf_events_save_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$all_day = ! empty( $_POST['gasf_all_day'] );

		$start_date = sanitize_text_field( wp_unslash( $_POST['gasf_start_date'] ?? '' ) );
		$start_time = $all_day ? '00:00' : sanitize_text_field( wp_unslash( $_POST['gasf_start_time'] ?? '00:00' ) );
		$end_date   = sanitize_text_field( wp_unslash( $_POST['gasf_end_date'] ?? '' ) );
		$end_time   = $all_day ? '23:59' : sanitize_text_field( wp_unslash( $_POST['gasf_end_time'] ?? '' ) );

		if ( ! $end_date && $start_date ) {
			$end_date = $start_date;
			$end_time = $all_day ? '23:59' : ( $end_time ?: $start_time );
		}

		$start = $start_date ? trim( $start_date . ' ' . ( $start_time ?: '00:00' ) . ':00' ) : '';
		$end   = $end_date ? trim( $end_date . ' ' . ( $end_time ?: '00:00' ) . ':00' ) : '';

		// Guard: end must be >= start.
		if ( $start && $end && Meta::local_to_ts( $end ) < Meta::local_to_ts( $start ) ) {
			$end = $start;
		}

		update_post_meta( $post_id, Meta::START, $start );
		update_post_meta( $post_id, Meta::END, $end );
		update_post_meta( $post_id, Meta::ALL_DAY, $all_day ? 1 : 0 );
		Meta::recompute_timestamps( $post_id );

		$status = sanitize_text_field( wp_unslash( $_POST['gasf_status'] ?? '' ) );
		update_post_meta( $post_id, Meta::STATUS, in_array( $status, Meta::STATUSES, true ) ? $status : '' );
		update_post_meta( $post_id, Meta::STATUS_REASON, sanitize_text_field( wp_unslash( $_POST['gasf_status_reason'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::ONLINE_LINK, esc_url_raw( wp_unslash( $_POST['gasf_online_link'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::MORE_INFO_URL, esc_url_raw( wp_unslash( $_POST['gasf_more_info_url'] ?? '' ) ) );
		update_post_meta( $post_id, Meta::MORE_INFO_TITLE, sanitize_text_field( wp_unslash( $_POST['gasf_more_info_title'] ?? '' ) ) );

		$cost = (float) ( $_POST['gasf_cost'] ?? 0 );
		if ( $cost > 0 ) {
			update_post_meta( $post_id, Meta::COST, $cost );
		} else {
			delete_post_meta( $post_id, Meta::COST );
		}

		// Venue override (only stored when a name is given).
		$venue_in = (array) ( $_POST['gasf_venue'] ?? [] );
		if ( ! empty( $venue_in['name'] ) ) {
			$venue_clean = array_map( 'sanitize_text_field', wp_unslash( $venue_in ) );
			update_post_meta( $post_id, Meta::VENUE_OVERRIDE, $venue_clean );
			// Remember it for next time — this is the whole point for the subgroups,
			// who hit the same handful of outside venues year after year.
			Venues::remember( $venue_clean );
		} else {
			delete_post_meta( $post_id, Meta::VENUE_OVERRIDE );
		}

		// Provenance: stamp manual on first save if unset.
		if ( '' === (string) get_post_meta( $post_id, Meta::SOURCE, true ) ) {
			update_post_meta( $post_id, Meta::SOURCE, 'manual' );
		}

		// The sync pin (meaningful for any feed-sourced event: facebook, ics, …).
		$event = Event::get( $post_id );
		if ( $event && $event->is_synced() ) {
			update_post_meta( $post_id, Meta::SYNC_LOCKED, empty( $_POST['gasf_sync_locked'] ) ? 0 : 1 );

			// Auto-pin on edit: if the maintainer changed a synced field but left the
			// pin off, pin it anyway so their edit isn't reverted by the next sync.
			if ( empty( $_POST['gasf_sync_locked'] ) ) {
				$snap = (string) get_post_meta( $post_id, Meta::FB_SNAPSHOT, true );
				if ( '' !== $snap && $snap !== Event_Ingest::snapshot_post( $post_id ) ) {
					update_post_meta( $post_id, Meta::SYNC_LOCKED, 1 );
				}
			}
		}

		// Recurrence — only on a standalone/master event, never a generated occurrence,
		// and never while we're mid-generation (guards against save_post recursion).
		$role = (string) get_post_meta( $post_id, Meta::SERIES_ROLE, true );
		if ( 'occurrence' !== $role && ! Series::is_generating() ) {
			$repeat = sanitize_text_field( wp_unslash( $_POST['gasf_repeat'] ?? '' ) );
			$repeat = in_array( $repeat, Meta::REPEATS, true ) ? $repeat : '';
			if ( $repeat ) {
				$until = sanitize_text_field( wp_unslash( $_POST['gasf_repeat_until'] ?? '' ) );
				$count = (int) ( $_POST['gasf_repeat_count'] ?? 0 );
				update_post_meta( $post_id, Meta::REPEAT, $repeat );
				update_post_meta( $post_id, Meta::REPEAT_UNTIL, $until );
				update_post_meta( $post_id, Meta::REPEAT_COUNT, $count );
				Series::sync_from_master( $post_id, $repeat, $until, $count, ! empty( $_POST['gasf_apply_future'] ) );
			} else {
				// Repeat turned off: clear the rule. Existing occurrences are left intact
				// (use the "Delete series" row action to remove them).
				delete_post_meta( $post_id, Meta::REPEAT );
				delete_post_meta( $post_id, Meta::REPEAT_UNTIL );
				delete_post_meta( $post_id, Meta::REPEAT_COUNT );
				if ( '' === $role ) {
					update_post_meta( $post_id, Meta::SERIES_ROLE, 'single' );
				}
			}
		}
	}
}
