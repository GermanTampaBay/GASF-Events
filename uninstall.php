<?php
/**
 * Uninstall cleanup.
 *
 * Conservative on purpose: removes only the plugin's own options and the
 * capabilities it added. It does NOT delete `gasf_event` posts or `_gasf_*`
 * meta — event data is the club's, not the plugin's, and must survive an
 * accidental uninstall. (Permanent data removal, if ever wanted, is a
 * deliberate WP-CLI step, not an uninstall side effect.)
 *
 * @package GASF_Events
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gasf_events_venue' );
delete_option( 'gasf_events_organizer' );
delete_option( 'gasf_events_default_image' );
delete_option( 'gasf_events_alert_email' );
delete_option( 'gasf_events_heroes_enabled' );
// NOTE: the scheduled-hero data (gasf_hero_entries / gasf_hero_recurring /
// gasf_hero_seeded / gasf_hero_lookahead_days) is club content, like the event
// posts — deliberately NOT deleted here, so an accidental uninstall doesn't wipe
// a maintainer's queued home-page banners.

// Credentials & integration config — remove on uninstall (these are not club
// content, unlike the event posts/meta, which are deliberately preserved).
delete_option( 'gasf_events_eventbrite' ); // Eventbrite private token
delete_option( 'gasf_events_feeds' );      // Facebook page access tokens + ICS feeds
delete_option( 'gasf_events_gcal' );
delete_option( 'gasf_events_enable_sync' );
delete_option( 'gasf_events_caps_ver' );
delete_transient( 'gasf_events_gcal_token' );

// Settings (Events → Settings) and derived bookkeeping. gasf_events_venues is
// a remembered form fill that Venues::all() rebuilds from the event posts on
// first read, so dropping it loses nothing.
foreach ( [
	'gasf_events_dinner_filter', 'gasf_events_bayern_filter', 'gasf_events_type_rules',
	'gasf_events_venues',
	'gasf_events_tombstones', 'gasf_events_pub_queue', 'gasf_events_last_run', 'gasf_events_sync_log',
	'gasf_events_gbp_enable', 'gasf_events_gbp_location', 'gasf_events_gbp_window', 'gasf_events_gbp_buffer',
	'gasf_events_gbp_hash', 'gasf_events_gbp_last', 'gasf_events_gbp_health', 'gasf_events_gbp_mailed',
	'gasf_hero_field_memory', // quick-create pre-fill cache, not the hero queue itself
	'gasf_events_heartbeat', 'gasf_events_heartbeat_mailed', 'gasf_events_last_purge',
	'gasf_events_webhooks', 'gasf_events_webhook_queue', // receiver URLs + secrets
] as $gasf_opt ) {
	delete_option( $gasf_opt );
}
foreach ( [
	'gasf_events_gbp_token', 'gasf_events_gbp_regular', 'gasf_events_update_check',
	'gasf_events_asset_ver', 'gasf_ics_all', 'gasf_events_heartbeat_check',
] as $gasf_tr ) {
	delete_transient( $gasf_tr );
}
// Per-feed, per-viewer and per-week caches carry an id in the name, so they
// are swept by prefix: feed .ics last-good copies, view-counter dedup and
// rate buckets, Welton Oktoberfest-week answers, feed-run result notices.
global $wpdb;
foreach ( [ 'gasf_feed_ics_lg_', '_transient_gasf_feed_ics_', '_transient_timeout_gasf_feed_ics_',
	'_transient_gasf_v_', '_transient_timeout_gasf_v_', '_transient_gasf_vrate_', '_transient_timeout_gasf_vrate_',
	'_transient_gasf_welton_okt_', '_transient_timeout_gasf_welton_okt_',
	'_transient_gasf_feeds_result_', '_transient_timeout_gasf_feeds_result_' ] as $gasf_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $gasf_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB
}

$caps = [
	'edit_gasf_event', 'read_gasf_event', 'delete_gasf_event',
	'edit_gasf_events', 'edit_others_gasf_events', 'publish_gasf_events',
	'read_private_gasf_events', 'delete_gasf_events', 'delete_private_gasf_events',
	'delete_published_gasf_events', 'delete_others_gasf_events',
	'edit_private_gasf_events', 'edit_published_gasf_events',
];
foreach ( [ 'administrator', 'editor' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
