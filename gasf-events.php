<?php
/**
 * Plugin Name:       GASF Events
 * Plugin URI:        https://github.com/GermanTampaBay/GASF-Events
 * Description:       Lean, native events for the German American Society — replaces Modern Events Calendar. Owns event data, display, Facebook import, Eventbrite/Google syndication, public feeds, and the home-page hero banners.
 * Version:           0.29.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            flinchbot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gasf-events
 * Update URI:        https://github.com/GermanTampaBay/GASF-Events
 *
 * @package GASF_Events
 *
 * Architecture: see docs/ARCHITECTURE.md. During the parallel-run dev phase the
 * CPT registers under the temporary slug `gasf-events` so it never collides with
 * the live MEC `/events/` URLs; at cutover the slug switches to `events`.
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-buster for the enqueued CSS/JS, read FROM THE PLUGIN HEADER rather
 * than repeated as a literal.
 *
 * It was a second hardcoded string, and it silently drifted three releases
 * behind: 0.18.0, 0.19.0 and 0.19.1 all shipped while every visitor kept being
 * served `gasf-events.css?ver=0.17.2` — the same URL they already had cached,
 * so a CSS change could not reach anybody no matter how many times the plugin
 * updated. Deriving it means the header bump that ships a release is also the
 * bump that busts the cache, and the two can never disagree again.
 *
 * get_file_data() is a cheap header read; the result is cached for a day so
 * it is not re-read on every request. get_plugin_data() is not used because
 * it lives in wp-admin and is unavailable on front-end requests.
 */
$gasf_events_ver = get_transient( 'gasf_events_asset_ver' );
if ( ! is_string( $gasf_events_ver ) || '' === $gasf_events_ver ) {
	$gasf_events_ver = get_file_data( __FILE__, array( 'v' => 'Version' ) )['v'];
	if ( '' === $gasf_events_ver ) { $gasf_events_ver = '0'; } // never cache-key on empty
	set_transient( 'gasf_events_asset_ver', $gasf_events_ver, DAY_IN_SECONDS );
}
define( 'GASF_EVENTS_VERSION', $gasf_events_ver );
unset( $gasf_events_ver );
define( 'GASF_EVENTS_FILE', __FILE__ );
define( 'GASF_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GASF_EVENTS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The custom post type slug. Code refers to this constant, never a literal,
 * so the temp→final ('gasf-events'→'events') cutover is a one-line change.
 */
define( 'GASF_EVENTS_CPT', 'gasf_event' );

/**
 * URL rewrite base. Temp slug during parallel run; flip to 'events' at cutover
 * (see docs/ARCHITECTURE.md §8).
 */
if ( ! defined( 'GASF_EVENTS_REWRITE_SLUG' ) ) {
	define( 'GASF_EVENTS_REWRITE_SLUG', 'events' ); // cutover (was 'gasf-events' during the parallel run)
}

require_once GASF_EVENTS_DIR . 'includes/class-plugin.php';
require_once GASF_EVENTS_DIR . 'includes/class-updater.php';
( new Updater( __FILE__ ) )->register_hooks();

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

// Boot after all plugins load so we can detect Yoast, MEC (during parallel run), etc.
add_action( 'plugins_loaded', static function () {
	Plugin::instance()->boot();
} );
