<?php
/**
 * Change propagation: purge the page cache whenever the calendar changes.
 *
 * The host serves a 24-hour page cache (heroes.php documents it and already
 * purges it when a banner activates). Until now no event save, status
 * change, trash or feed run purged anything, so a cancellation could show as
 * "Scheduled" on the calendar page for a day. ARCHITECTURE §10 question 4,
 * finally answered.
 *
 * Purges are collected per request and run once on shutdown, so a feed run
 * that writes forty events purges once, not forty times. Every purge target
 * is guarded by has_action()/function_exists(): the plugin does not know
 * which cache the host runs, it just tells every one it recognises. Anything
 * else hooks `gasf_events_purge`.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const OPT_LAST = 'gasf_events_last_purge';

	/** @var bool A purge is due at the end of this request. */
	private static $pending = false;

	/** Settings whose change alters rendered pages. */
	const DISPLAY_OPTIONS = [
		Settings::OPT_VENUE, Settings::OPT_ORGANIZER, Settings::OPT_DEFAULT_IMG, Settings::OPT_HEROES,
		Settings::OPT_DINNER_FILTER, Settings::OPT_BAYERN_FILTER, Settings::OPT_TYPE_RULES,
	];

	public function register_hooks(): void {
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ __CLASS__, 'on_save' ], 30, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 30, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ], 30, 2 );
		add_action( 'trashed_post', [ __CLASS__, 'on_delete' ], 30, 1 );
		add_action( 'updated_option', [ __CLASS__, 'on_option' ], 30, 1 );
		// Fired by Feeds::run() after a real (non-dry) run that changed anything.
		add_action( 'gasf_events_feed_run_complete', [ __CLASS__, 'schedule' ] );
		add_action( 'shutdown', [ __CLASS__, 'flush_pending' ] );
	}

	/* ---- triggers ----------------------------------------------------- */

	public static function on_save( $post_id, $post, $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( $post instanceof \WP_Post && 'auto-draft' === $post->post_status ) {
			return; // the editor opening "Add New" is not a change anyone can see
		}
		self::schedule();
	}

	public static function on_transition( $new, $old, $post ): void {
		if ( $post instanceof \WP_Post && GASF_EVENTS_CPT === $post->post_type && $new !== $old ) {
			self::schedule();
		}
	}

	public static function on_delete( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( GASF_EVENTS_CPT === $type ) {
			self::schedule();
		}
	}

	public static function on_option( $option ): void {
		if ( in_array( (string) $option, self::DISPLAY_OPTIONS, true ) ) {
			self::schedule();
		}
	}

	/** Mark a purge due; it runs once at shutdown. */
	public static function schedule(): void {
		self::$pending = true;
	}

	public static function flush_pending(): void {
		if ( self::$pending ) {
			self::$pending = false;
			self::purge();
		}
	}

	/* ---- the purge ---------------------------------------------------- */

	/**
	 * Purge everything that could hold a stale calendar. Returns the names of
	 * the caches it reached, for the Health screen and the log.
	 *
	 * @return string[]
	 */
	public static function purge(): array {
		$done = [];

		// Our own caches first.
		delete_transient( 'gasf_ics_all' );
		$done[] = 'calendar.ics';

		// Host / plugin page caches, each only if present.
		if ( has_action( 'epc_purge' ) ) {               // Endurance Page Cache (Bluehost)
			do_action( 'epc_purge' );
			$done[] = 'Endurance Page Cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) { // WP Rocket
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {      // W3 Total Cache
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) { // WP Super Cache
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) { // WP Fastest Cache
			wpfc_clear_all_cache( true );
			$done[] = 'WP Fastest Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) { // SiteGround
			sg_cachepress_purge_cache();
			$done[] = 'SiteGround Optimizer';
		}
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			\LiteSpeed_Cache_API::purge_all();
			$done[] = 'LiteSpeed Cache';
		}
		if ( has_action( 'cloudflare_purge_everything' ) ) { // Cloudflare plugin (purge hook)
			do_action( 'cloudflare_purge_everything' );
			$done[] = 'Cloudflare';
		}

		/**
		 * Purge any cache the plugin does not know about (nginx helper, a CDN
		 * API, GASF-Utilities' own purge). Runs once per request that changed
		 * the calendar.
		 *
		 * @param string[] $done Names of the caches already purged.
		 */
		do_action( 'gasf_events_purge', $done );

		update_option( self::OPT_LAST, [ 'ts' => time(), 'targets' => $done ], false );
		return $done;
	}
}
