<?php
/**
 * All Events bulk actions: Publish to Eventbrite, Export CSV, Export ICS.
 * (Move to Trash is WordPress-native.) See docs/ARCHITECTURE.md §4.5.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Bulk_Actions {

	public function register_hooks(): void {
		$screen = 'edit-' . GASF_EVENTS_CPT;
		add_filter( "bulk_actions-{$screen}", [ $this, 'register' ] );
		add_filter( "handle_bulk_actions-{$screen}", [ $this, 'handle' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	public function register( array $actions ): array {
		$actions['gasf_publish_eventbrite'] = __( 'Publish to Eventbrite', 'gasf-events' );
		$actions['gasf_export_csv']         = __( 'Export CSV', 'gasf-events' );
		$actions['gasf_export_ics']         = __( 'Export ICS', 'gasf-events' );
		return $actions;
	}

	/**
	 * @param string $redirect
	 * @param string $action
	 * @param int[]  $post_ids
	 */
	public function handle( string $redirect, string $action, array $post_ids ) {
		$post_ids = array_map( 'intval', $post_ids );

		if ( 'gasf_export_csv' === $action ) {
			$this->stream_csv( $post_ids ); // exits
		}
		if ( 'gasf_export_ics' === $action ) {
			$this->stream_ics( $post_ids ); // exits
		}
		if ( 'gasf_publish_eventbrite' === $action ) {
			if ( ! current_user_can( 'edit_gasf_events' ) ) {
				return $redirect;
			}
			$ids = [];
			foreach ( $post_ids as $id ) {
				if ( Event::get( $id ) ) {
					Syndication::mark_for_publish( $id, 'eventbrite' );
					$ids[] = $id;
				}
			}
			Syndication::process_queue(); // push this batch now; overflow drains via cron.
			// Report ACTUAL outcomes: published / failed / still-queued (deferred).
			$ok = 0; $err = 0;
			foreach ( $ids as $id ) {
				$e     = Event::get( $id );
				$state = $e ? $e->published_to( 'eventbrite' ) : [];
				if ( 'published' === ( $state['status'] ?? '' ) ) {
					$ok++;
				} elseif ( 'error' === ( $state['status'] ?? '' ) ) {
					$err++;
				}
			}
			$deferred = max( 0, count( $ids ) - $ok - $err );
			return add_query_arg( [ 'gasf_eb_ok' => $ok, 'gasf_eb_err' => $err, 'gasf_eb_deferred' => $deferred ], $redirect );
		}
		return $redirect;
	}

	private function stream_csv( array $ids ): void {
		if ( ! current_user_can( 'edit_gasf_events' ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gasf-events-' . gmdate( 'Ymd' ) . '.csv"' );
		// Neutralize spreadsheet formula injection: feed/author-controlled text
		// (title, venue, …) starting with = + - @ tab/CR executes in Excel/Sheets.
		$safe = static function ( $v ): string {
			$v = (string) $v;
			return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
		};
		$out = fopen( 'php://output', 'w' );
		// PHP 8.4 deprecates relying on fputcsv()'s default $escape, so the
		// separator, enclosure and escape are spelled out (the same values).
		fputcsv( $out, [ 'ID', 'Title', 'Added', 'Start', 'End', 'All day', 'Status', 'Source', 'Venue', 'URL', 'Views', 'Eventbrite URL' ], ',', '"', '\\' );
		foreach ( $ids as $id ) {
			$e = Event::get( $id );
			if ( ! $e ) {
				continue;
			}
			$v = $e->venue();
			fputcsv( $out, [
				$e->id(),
				$safe( $e->title() ),
				get_post_time( 'Y-m-d', false, $e->post() ),
				$e->start() ? $e->start()->format( 'Y-m-d H:i' ) : '',
				$e->end() ? $e->end()->format( 'Y-m-d H:i' ) : '',
				$e->is_all_day() ? 'yes' : 'no',
				$e->status() ?: 'scheduled',
				$e->source(),
				$safe( $v['name'] ?? '' ),
				$safe( $e->permalink() ),
				$e->views(),
				$safe( $e->eventbrite_url() ),
			], ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	private function stream_ics( array $ids ): void {
		if ( ! current_user_can( 'edit_gasf_events' ) ) {
			return;
		}
		$events = array_filter( array_map( [ Event::class, 'get' ], $ids ) );
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gasf-events-' . gmdate( 'Ymd' ) . '.ics"' );
		echo Add_To_Calendar::ics_document( $events ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function notices(): void {
		if ( ! isset( $_GET['gasf_eb_ok'] ) && ! isset( $_GET['gasf_eb_err'] ) && ! isset( $_GET['gasf_eb_deferred'] ) ) {
			return;
		}
		$ok       = isset( $_GET['gasf_eb_ok'] ) ? (int) $_GET['gasf_eb_ok'] : 0;
		$err      = isset( $_GET['gasf_eb_err'] ) ? (int) $_GET['gasf_eb_err'] : 0;
		$deferred = isset( $_GET['gasf_eb_deferred'] ) ? (int) $_GET['gasf_eb_deferred'] : 0;

		$msg = sprintf( /* translators: %d events */ _n( '%d event published to Eventbrite.', '%d events published to Eventbrite.', $ok, 'gasf-events' ), $ok );
		if ( $deferred > 0 ) {
			$msg .= ' ' . sprintf( /* translators: %d events */ _n( '%d more queued (processing in the background).', '%d more queued (processing in the background).', $deferred, 'gasf-events' ), $deferred );
		}
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
		if ( $err > 0 ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %d events */ _n( '%d event failed to publish — see the Eventbrite column for the error.', '%d events failed to publish — see the Eventbrite column for details.', $err, 'gasf-events' ), $err ) )
			);
		}
	}
}
