<?php
/**
 * Plugin bootstrap: loads modules and wires activation/deactivation.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Require class files. Kept explicit (no autoloader) to match the club's
	 * existing plugin style and keep load order obvious.
	 */
	private function load(): void {
		$dir = GASF_EVENTS_DIR . 'includes/';
		require_once $dir . 'class-meta.php';
		require_once $dir . 'class-event.php';
		require_once $dir . 'class-post-type.php';
		require_once $dir . 'class-settings.php';
		require_once $dir . 'class-recurrence.php';
		require_once $dir . 'class-series.php';
		require_once $dir . 'class-migrator.php';
		require_once $dir . 'class-calendar.php';
		require_once $dir . 'class-assets.php';
		require_once $dir . 'class-add-to-calendar.php';
		require_once $dir . 'class-schema.php';
		require_once $dir . 'class-shortcodes.php';
		require_once $dir . 'class-welton.php';
		// Home-page heroes (moved from GASF-Utilities). Procedural, self-registering
		// on include; heroes.php MUST precede recurring-heroes.php (shared helpers).
		// Requires class-settings.php (loaded above) for the enable gate.
		require_once $dir . 'heroes.php';
		require_once $dir . 'recurring-heroes.php';
		require_once $dir . 'class-single.php';
		require_once $dir . 'class-print.php';
		require_once $dir . 'class-fb-client.php';
		require_once $dir . 'class-cover-sideloader.php';
		require_once $dir . 'class-ics-parser.php';
		require_once $dir . 'class-event-ingest.php';
		require_once $dir . 'class-event-merge.php';
		require_once $dir . 'class-venues.php';
		require_once $dir . 'class-google-calendar.php';
		require_once $dir . 'class-google-business-profile.php';
		require_once $dir . 'class-hours-sync.php';
		require_once $dir . 'class-gbp-notice.php';
		require_once $dir . 'class-alerts.php';
		require_once $dir . 'class-feeds.php';
		require_once $dir . 'class-syndication.php';  // interface + registry (before destinations)
		require_once $dir . 'class-image-uploader.php';
		require_once $dir . 'class-eventbrite.php';
		require_once $dir . 'class-stats.php';
		require_once $dir . 'class-rest.php';
		if ( is_admin() ) {
			require_once $dir . 'class-meta-box.php';
			require_once $dir . 'class-admin-list.php';
			require_once $dir . 'class-feeds-admin.php';
			require_once $dir . 'class-bulk-actions.php';
			require_once $dir . 'class-event-duplicate.php';
			require_once $dir . 'class-venues-admin.php';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-cli.php';
			\WP_CLI::add_command( 'gasf-events', CLI::class );
		}
	}

	/**
	 * Wire runtime hooks. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->load();

		( new Post_Type() )->register_hooks();
		( new Settings() )->register_hooks();
		( new Assets() )->register_hooks();
		( new Add_To_Calendar() )->register_hooks();
		( new Schema() )->register_hooks();
		( new Shortcodes() )->register_hooks();
		( new Welton() )->register_hooks();
		( new Single() )->register_hooks();
		( new Print_View() )->register_hooks();
		( new Rest() )->register_hooks();
		( new Feeds() )->register_hooks();
		( new Hours_Sync() )->register_hooks();
		( new GBP_Notice() )->register_hooks();
		( new Syndication() )->register_hooks();
		( new Stats() )->register_hooks();

		if ( is_admin() ) {
			( new Meta_Box() )->register_hooks();
			( new Admin_List() )->register_hooks();
			( new Series() )->register_hooks();
			( new Feeds_Admin() )->register_hooks();
			( new Bulk_Actions() )->register_hooks();
			( new Event_Duplicate() )->register_hooks();
			( new Venues_Admin() )->register_hooks();
		}

		load_plugin_textdomain( 'gasf-events', false, dirname( plugin_basename( GASF_EVENTS_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: register the CPT then flush rewrites so /<slug>/ resolves.
	 */
	public static function activate(): void {
		require_once GASF_EVENTS_DIR . 'includes/class-meta.php';
		require_once GASF_EVENTS_DIR . 'includes/class-event.php';
		require_once GASF_EVENTS_DIR . 'includes/class-post-type.php';
		$pt = new Post_Type();
		$pt->register_post_type();
		Post_Type::grant_caps();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: flush rewrites so the CPT's rules are dropped cleanly.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'gasf_events_sync' );
		wp_clear_scheduled_hook( 'gasf_events_drain' );
		wp_clear_scheduled_hook( 'gasf_hero_recurring_cron' ); // owned by recurring-heroes.php
		flush_rewrite_rules();
	}
}
