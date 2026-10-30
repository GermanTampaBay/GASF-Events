<?php
/**
 * The "All Events" list table: columns, sortable event date, and the
 * text / date-range / status / source filters. See §4.5.
 *
 * Bulk Actions (Publish to Eventbrite, Export CSV/ICS) and the Views/Stats
 * surfacing are wired in the syndication + stats phase (P6); the Views and
 * Eventbrite columns render here now and fill in then.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Admin_List {

	public function register_hooks(): void {
		$cpt = GASF_EVENTS_CPT;
		add_filter( "manage_{$cpt}_posts_columns", [ $this, 'columns' ] );
		add_action( "manage_{$cpt}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );
		add_filter( "manage_edit-{$cpt}_sortable_columns", [ $this, 'sortable' ] );
		add_action( 'restrict_manage_posts', [ $this, 'filters_ui' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_query' ] );
	}

	public function columns( array $cols ): array {
		$out = [];
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$out['gasf_thumb'] = __( 'Cover', 'gasf-events' );
			}
			if ( 'date' === $key ) {
				$label = __( 'Added', 'gasf-events' ); // WP's post-date column = when it entered the system.
				$out['gasf_event_date'] = __( 'Event date', 'gasf-events' );
				$out['gasf_recurring']  = __( 'Recurring', 'gasf-events' );
				$out['gasf_status']     = __( 'Status', 'gasf-events' );
				$out['gasf_source']     = __( 'Source', 'gasf-events' );
				$out['gasf_views']      = __( 'Views', 'gasf-events' );
				$out['gasf_eb']         = __( 'Published', 'gasf-events' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public function sortable( array $cols ): array {
		$cols['gasf_event_date'] = 'gasf_event_date';
		$cols['gasf_views']      = 'gasf_views';
		return $cols;
	}

	public function render_column( string $column, int $post_id ): void {
		$event = Event::get( $post_id );
		if ( ! $event ) {
			return;
		}
		switch ( $column ) {
			case 'gasf_thumb':
				echo has_post_thumbnail( $post_id )
					? get_the_post_thumbnail( $post_id, [ 60, 60 ] )
					: '<span style="color:#bbb;">—</span>';
				break;

			case 'gasf_event_date':
				$s = $event->start();
				if ( $s ) {
					$fmt = $event->is_all_day() ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
					echo esc_html( wp_date( $fmt, $event->start_ts() ) );
					if ( $event->is_multiday() && $event->end() ) {
						echo '<br><small>→ ' . esc_html( wp_date( get_option( 'date_format' ), $event->end_ts() ) ) . '</small>';
					}
					if ( $event->is_past() ) {
						echo ' <small style="color:#999;">(' . esc_html__( 'past', 'gasf-events' ) . ')</small>';
					}
				} else {
					echo '<span style="color:#cc0000;">' . esc_html__( 'no date', 'gasf-events' ) . '</span>';
				}
				break;

			case 'gasf_recurring':
				if ( $event->is_recurring() && $event->series_id() ) {
					$url = add_query_arg(
						[ 'post_type' => GASF_EVENTS_CPT, 'gasf_series' => $event->series_id() ],
						admin_url( 'edit.php' )
					);
					echo '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Show all events in this series', 'gasf-events' ) . '" style="color:#46810b;font-size:18px;text-decoration:none;">&#10003;</a>';
					$role = (string) get_post_meta( $post_id, Meta::SERIES_ROLE, true );
					if ( 'master' === $role ) {
						echo '<br><small style="color:#646970;">' . esc_html__( 'first date', 'gasf-events' ) . '</small>';
					} elseif ( Series::is_customized( $post_id ) ) {
						echo '<br><small style="color:#996800;" title="' . esc_attr__( 'Edited by hand: changes to the whole series skip this date.', 'gasf-events' ) . '">' . esc_html__( 'edited', 'gasf-events' ) . '</small>';
					}
				} else {
					echo '<span style="color:#ccc;">—</span>';
				}
				break;

			case 'gasf_status':
				$s = $event->status();
				echo $s
					? '<span style="color:#b32d2e;font-weight:600;">' . esc_html( $event->status_label() ) . '</span>'
					: esc_html( $event->status_label() );
				break;

			case 'gasf_source':
				$src = $event->source();
				if ( 'facebook' === $src ) {
					echo '<span style="background:#1877f2;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">FB</span>';
				} elseif ( 'ics' === $src ) {
					echo '<span style="background:#555;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">ICS</span>';
				} else {
					echo esc_html__( 'Manual', 'gasf-events' );
				}
				// The pin is invisible until it bites ("why didn't Facebook's change
				// come through?"), so it is shown wherever the source is.
				if ( $event->is_synced() && get_post_meta( $post_id, Meta::SYNC_LOCKED, true ) ) {
					echo ' <span title="' . esc_attr__( 'Pinned: the feed sync does not overwrite this event.', 'gasf-events' ) . '">📌</span>';
				}
				break;

			case 'gasf_views':
				echo esc_html( number_format_i18n( $event->views() ) );
				break;

			case 'gasf_eb':
				$pub = $event->published();
				if ( ! $pub ) {
					echo '<span style="color:#ccc;">—</span>';
					break;
				}
				$bits = [];
				foreach ( $pub as $dkey => $state ) {
					$dest  = Destinations::get( $dkey );
					$label = $dest ? $dest->label() : ucfirst( $dkey );
					if ( 'error' === ( $state['status'] ?? '' ) ) {
						$bits[] = '<span style="color:#b32d2e;" title="' . esc_attr( (string) ( $state['error'] ?? '' ) ) . '">' . esc_html( $label ) . ' ⚠</span>';
					} elseif ( ! empty( $state['url'] ) ) {
						$bits[] = '<a href="' . esc_url( $state['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
					} else {
						$bits[] = esc_html( $label );
					}
				}
				echo wp_kses_post( implode( ', ', $bits ) );
				break;
		}
	}

	/** Render the date-range / status / source filter controls. */
	public function filters_ui( string $post_type ): void {
		if ( GASF_EVENTS_CPT !== $post_type ) {
			return;
		}
		$from   = isset( $_GET['gasf_from'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_from'] ) ) : '';
		$to     = isset( $_GET['gasf_to'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_to'] ) ) : '';
		$status = isset( $_GET['gasf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_status'] ) ) : '';
		$source = isset( $_GET['gasf_source'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_source'] ) ) : '';
		?>
		<label class="screen-reader-text" for="gasf_from"><?php esc_html_e( 'Event date from', 'gasf-events' ); ?></label>
		<input type="date" name="gasf_from" id="gasf_from" value="<?php echo esc_attr( $from ); ?>" title="<?php esc_attr_e( 'Event date from', 'gasf-events' ); ?>">
		<input type="date" name="gasf_to" value="<?php echo esc_attr( $to ); ?>" title="<?php esc_attr_e( 'Event date to', 'gasf-events' ); ?>">
		<select name="gasf_status">
			<option value=""><?php esc_html_e( 'Any status', 'gasf-events' ); ?></option>
			<?php foreach ( [ 'cancelled' => __( 'Cancelled', 'gasf-events' ), 'postponed' => __( 'Postponed', 'gasf-events' ), 'sold_out' => __( 'Sold out', 'gasf-events' ), 'online_only' => __( 'Online only', 'gasf-events' ) ] as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="gasf_source">
			<option value=""><?php esc_html_e( 'Any source', 'gasf-events' ); ?></option>
			<option value="manual" <?php selected( $source, 'manual' ); ?>><?php esc_html_e( 'Manual', 'gasf-events' ); ?></option>
			<option value="facebook" <?php selected( $source, 'facebook' ); ?>><?php esc_html_e( 'Facebook', 'gasf-events' ); ?></option>
			<option value="ics" <?php selected( $source, 'ics' ); ?>><?php esc_html_e( 'iCal feed', 'gasf-events' ); ?></option>
		</select>
		<?php $pinned = ! empty( $_GET['gasf_pinned'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<label style="margin-left:4px;"><input type="checkbox" name="gasf_pinned" value="1" <?php checked( $pinned ); ?>> <?php esc_html_e( 'Pinned only', 'gasf-events' ); ?></label>
		<?php
	}

	/** Apply sorting + filters to the admin events query. */
	public function apply_query( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || GASF_EVENTS_CPT !== $q->get( 'post_type' ) ) {
			return;
		}

		// Sorting.
		$orderby = $q->get( 'orderby' );
		if ( 'gasf_event_date' === $orderby ) {
			$q->set( 'meta_key', Meta::START_TS );
			$q->set( 'orderby', 'meta_value_num' );
		} elseif ( 'gasf_views' === $orderby ) {
			$q->set( 'meta_key', Meta::VIEWS );
			$q->set( 'orderby', 'meta_value_num' );
		}

		// Filters.
		$meta_query = [];

		$from = isset( $_GET['gasf_from'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_from'] ) ) : '';
		$to   = isset( $_GET['gasf_to'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_to'] ) ) : '';
		if ( $from || $to ) {
			$range = [ 'key' => Meta::START_TS, 'type' => 'NUMERIC' ];
			$min   = $from ? Meta::local_to_ts( $from . ' 00:00:00' ) : null;
			$max   = $to ? Meta::local_to_ts( $to . ' 23:59:59' ) : null;
			if ( null !== $min && null !== $max ) {
				$range['value']   = [ $min, $max ];
				$range['compare'] = 'BETWEEN';
			} elseif ( null !== $min ) {
				$range['value']   = $min;
				$range['compare'] = '>=';
			} else {
				$range['value']   = $max;
				$range['compare'] = '<=';
			}
			$meta_query[] = $range;
		}

		$status = isset( $_GET['gasf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_status'] ) ) : '';
		if ( $status ) {
			$meta_query[] = [ 'key' => Meta::STATUS, 'value' => $status ];
		}

		$source = isset( $_GET['gasf_source'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_source'] ) ) : '';
		if ( 'manual' === $source ) {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => Meta::SOURCE, 'value' => 'manual' ],
				[ 'key' => Meta::SOURCE, 'compare' => 'NOT EXISTS' ],
			];
		} elseif ( 'facebook' === $source ) {
			$meta_query[] = [ 'key' => Meta::SOURCE, 'value' => 'facebook' ];
		} elseif ( 'ics' === $source ) {
			$meta_query[] = [ 'key' => Meta::SOURCE, 'value' => 'ics' ];
		}

		if ( ! empty( $_GET['gasf_pinned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta_query[] = [ 'key' => Meta::SYNC_LOCKED, 'value' => '1' ];
		}

		// "Show all events in this series" (from the Recurring-column link).
		$series = isset( $_GET['gasf_series'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_series'] ) ) : '';
		if ( $series ) {
			$meta_query[]  = [ 'key' => Meta::SERIES_ID, 'value' => $series ];
			// Within a series, order by the actual event date.
			$q->set( 'meta_key', Meta::START_TS );
			$q->set( 'orderby', 'meta_value_num' );
			$q->set( 'order', 'ASC' );
		}

		if ( $meta_query ) {
			$existing = (array) $q->get( 'meta_query' );
			$q->set( 'meta_query', array_merge( $existing, $meta_query ) );
		}
	}
}
