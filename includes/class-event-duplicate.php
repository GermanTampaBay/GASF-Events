<?php
/**
 * Event_Duplicate — a "Duplicate" row action on All Events, for the common case
 * of "same event, new date".
 *
 * The copy is always a DRAFT. A published duplicate would immediately reach the
 * public feed, the calendar, the Google Business Profile hours and the kiosk,
 * which is the opposite of what someone wants while they are still changing the
 * date on it.
 *
 * WHAT IS DELIBERATELY NOT COPIED. Provenance is the dangerous part: copying
 * _gasf_source_uid would put two posts behind one dedup key, so
 * Event_Ingest::find() would return whichever came first and prune_missing()
 * could draft the wrong one. Series membership is excluded for the same kind of
 * reason — a duplicate is a new standalone event, not another occurrence of the
 * original's recurrence. View counts belong to the original.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Event_Duplicate {

	const ACTION = 'gasf_duplicate_event';

	/**
	 * Meta the copy must NOT inherit.
	 *
	 * Provenance first: the copy is a hand-made event from this moment on, so it
	 * carries no feed identity. Then the sync bookkeeping that only means
	 * anything next to that identity, the series links, and the original's
	 * audience numbers.
	 */
	const SKIP_META = [
		Meta::SOURCE,
		Meta::SOURCE_UID,
		Meta::SOURCE_FEED,
		Meta::FB_EVENT_ID,
		Meta::FB_COVER_ID,
		Meta::FB_MISSING,
		Meta::FB_SNAPSHOT,
		Meta::SYNC_LOCKED,
		Meta::SERIES_ID,
		Meta::SERIES_ROLE,
		Meta::REPEAT,
		Meta::REPEAT_UNTIL,
		Meta::REPEAT_COUNT,
		Meta::VIEWS,
		Meta::VIEWS_KIOSK,
		Meta::VIEWS_DAILY,
		'_gasf_source_url',
		'_edit_lock',
		'_edit_last',
	];

	public function register_hooks(): void {
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/** Add "Duplicate" next to Edit / Trash on the events list. */
	public function row_action( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post || GASF_EVENTS_CPT !== $post->post_type ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . $post->ID ),
			self::ACTION . '_' . $post->ID
		);
		$actions['gasf_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Duplicate', 'gasf-events' )
		);
		return $actions;
	}

	/** Make the copy and drop the editor straight into it. */
	public function handle(): void {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot duplicate that event.', 'gasf-events' ) );
		}
		check_admin_referer( self::ACTION . '_' . $id );

		$new = self::copy( $id );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		// Land on the copy's edit screen: the only reason to duplicate is to
		// change something, so anything else would just be a second click.
		wp_safe_redirect( admin_url( 'post.php?post=' . $new . '&action=edit' ) );
		exit;
	}

	/**
	 * Duplicate $id as a draft. Returns the new post ID or WP_Error.
	 *
	 * @return int|\WP_Error
	 */
	public static function copy( int $id ) {
		$src = get_post( $id );
		if ( ! $src || GASF_EVENTS_CPT !== $src->post_type ) {
			return new \WP_Error( 'gasf_dup_missing', __( 'That event no longer exists.', 'gasf-events' ) );
		}

		$new_id = wp_insert_post( [
			'post_type'      => GASF_EVENTS_CPT,
			'post_status'    => 'draft',
			// Same title on purpose. The point of the copy is that it IS the same
			// event on another date, so a "(copy)" suffix would only be something
			// to delete every single time.
			'post_title'     => $src->post_title,
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'comment_status' => $src->comment_status,
			'ping_status'    => $src->ping_status,
			'menu_order'     => $src->menu_order,
		], true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;

		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				add_post_meta( $new_id, $key, maybe_unserialize( $v ) );
			}
		}

		// Hand-made from here, and standalone rather than part of the original's
		// series — both of which the skip list above deliberately left unset.
		update_post_meta( $new_id, Meta::SOURCE, 'manual' );
		update_post_meta( $new_id, Meta::SERIES_ROLE, 'single' );
		Meta::recompute_timestamps( $new_id );

		foreach ( (array) get_object_taxonomies( GASF_EVENTS_CPT ) as $tax ) {
			$terms = wp_get_object_terms( $id, $tax, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}

		return $new_id;
	}
}
