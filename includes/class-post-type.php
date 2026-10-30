<?php
/**
 * Registers the `gasf_event` custom post type, its meta, and capabilities.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	/** Capabilities granted to event managers. */
	const CAPS = [
		'edit_gasf_event', 'read_gasf_event', 'delete_gasf_event',
		'edit_gasf_events', 'edit_others_gasf_events', 'publish_gasf_events',
		'read_private_gasf_events', 'delete_gasf_events', 'delete_private_gasf_events',
		'delete_published_gasf_events', 'delete_others_gasf_events',
		'edit_private_gasf_events', 'edit_published_gasf_events',
	];

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ Meta::class, 'register' ] );
		add_action( 'admin_init', [ $this, 'maybe_grant_caps' ] );
		add_action( 'pre_get_posts', [ $this, 'archive_query' ] );
	}

	/**
	 * The /<slug>/ archive is a list of events, not a blog: soonest first and
	 * only what has not finished yet. Without this the theme's archive.php
	 * ordered it by publish date with every past event included, and the
	 * single page's "All events" link led straight there. Feeds are left
	 * alone: an RSS reader wants newest-published first.
	 */
	public function archive_query( \WP_Query $q ): void {
		if ( is_admin() || $q->is_feed() || ! $q->is_main_query() || ! $q->is_post_type_archive( GASF_EVENTS_CPT ) ) {
			return;
		}
		$q->set( 'meta_key', Meta::START_TS );
		$q->set( 'orderby', 'meta_value_num' );
		$q->set( 'order', 'ASC' );
		$q->set( 'meta_query', [ [ 'key' => Meta::END_TS, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '>=' ] ] );
	}

	/**
	 * Self-heal capabilities on version change — covers git-pull updates that
	 * never re-run activation, and roles added after install.
	 */
	public function maybe_grant_caps(): void {
		if ( get_option( 'gasf_events_caps_ver' ) !== GASF_EVENTS_VERSION ) {
			self::grant_caps();
			update_option( 'gasf_events_caps_ver', GASF_EVENTS_VERSION, false );
		}
	}

	public function register_post_type(): void {
		$labels = [
			'name'               => _x( 'Events', 'post type general name', 'gasf-events' ),
			'singular_name'      => _x( 'Event', 'post type singular name', 'gasf-events' ),
			'menu_name'          => _x( 'Events', 'admin menu', 'gasf-events' ),
			'add_new'            => __( 'Add New', 'gasf-events' ),
			'add_new_item'       => __( 'Add New Event', 'gasf-events' ),
			'edit_item'          => __( 'Edit Event', 'gasf-events' ),
			'new_item'           => __( 'New Event', 'gasf-events' ),
			'view_item'          => __( 'View Event', 'gasf-events' ),
			'search_items'       => __( 'Search Events', 'gasf-events' ),
			'not_found'          => __( 'No events found', 'gasf-events' ),
			'not_found_in_trash' => __( 'No events found in Trash', 'gasf-events' ),
			'all_items'          => __( 'All Events', 'gasf-events' ),
		];

		register_post_type( GASF_EVENTS_CPT, [
			'labels'              => $labels,
			'public'              => true,
			'has_archive'         => true,
			'show_in_rest'        => true,
			'rest_base'           => 'gasf-events',
			'menu_icon'           => 'dashicons-calendar-alt',
			'menu_position'       => 25,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions', 'author', 'custom-fields' ],
			'rewrite'             => [ 'slug' => GASF_EVENTS_REWRITE_SLUG, 'with_front' => false ],
			'capability_type'     => [ 'gasf_event', 'gasf_events' ],
			'map_meta_cap'        => true,
			'hierarchical'        => false,
		] );
	}

	/**
	 * Grant event capabilities to the given roles. Called on activation.
	 * Administrator gets everything; Editor acts as the "Maintainer".
	 */
	public static function grant_caps(): void {
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Add compound (meta_key, meta_value) indexes on wp_postmeta so the numeric
	 * `_gasf_*_ts` range queries (every calendar view / feed / sort) use an index
	 * instead of filesorting the whole meta table. Idempotent.
	 *
	 * NOTE: this ALTERs the SHARED wp_postmeta table, so it is NOT run at
	 * activation (it could briefly lock the table while MEC is still live). Run
	 * it deliberately at cutover via `wp gasf-events ensure-indexes`.
	 *
	 * @return string[] human-readable result lines
	 */
	/**
	 * Read-only: which of the two indexes exist. For the Health screen, which
	 * must never ALTER a table on a page load.
	 *
	 * @return array{present:string[],missing:string[]}
	 */
	public static function indexes_present(): array {
		global $wpdb;
		$out = [ 'present' => [], 'missing' => [] ];
		foreach ( [ 'gasf_start_ts', 'gasf_end_ts' ] as $name ) {
			$exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s", $name ) ); // phpcs:ignore WordPress.DB
			$out[ $exists ? 'present' : 'missing' ][] = $name;
		}
		return $out;
	}

	public static function ensure_indexes(): array {
		global $wpdb;
		$out   = [];
		$table = $wpdb->postmeta;
		foreach ( [ 'gasf_start_ts', 'gasf_end_ts' ] as $name ) {
			$exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ); // phpcs:ignore WordPress.DB
			if ( $exists ) {
				$out[] = "{$name}: already present";
				continue;
			}
			// meta_value prefix length 12 comfortably covers a 10-digit unix ts.
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$name} (meta_key(20), meta_value(12))" ); // phpcs:ignore WordPress.DB
			$out[] = "{$name}: created";
		}
		return $out;
	}
}
