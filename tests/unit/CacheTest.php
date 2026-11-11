<?php
/**
 * Page-cache purging after a plugin update (class-cache.php, v0.38.2).
 *
 * v0.38.1 installed on the Krampus site, but logged-out visitors kept getting
 * the cached page and SpeedyCache's old combined stylesheet. These pin the
 * fix: a new version purges once, SpeedyCache included, assets and all.
 *
 * @package GASF_Events
 */

namespace SpeedyCache {
	// Stand-in for SpeedyCache's public purge entry point; records each call.
	if ( ! class_exists( '\SpeedyCache\Delete' ) ) {
		final class Delete {
			public static array $calls = [];
			public static function run( $actions ): void {
				self::$calls[] = $actions;
			}
		}
	}
}

namespace {
	if ( ! defined( 'GASF_EVENTS_VERSION' ) ) {
		define( 'GASF_EVENTS_VERSION', '9.9.9-test' );
	}
	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $k ) { return true; }
	}
	if ( ! function_exists( 'has_action' ) ) {
		function has_action( ...$a ) { return false; }
	}
	require_once GASF_EVENTS_DIR . 'includes/class-settings.php'; // Cache::DISPLAY_OPTIONS names its options
	require_once GASF_EVENTS_DIR . 'includes/class-cache.php';

	use GASF_Events\Cache;

	test( 'a new plugin version purges once, SpeedyCache combined CSS/JS included', function () {
		\SpeedyCache\Delete::$calls = [];
		update_option( Cache::OPT_PURGED_VER, '0.38.1' );

		Cache::on_version_change();
		Cache::flush_pending(); // what shutdown does

		assert_same( [ [ 'minified' => true ] ], \SpeedyCache\Delete::$calls );
		assert_same( GASF_EVENTS_VERSION, get_option( Cache::OPT_PURGED_VER ) );
		assert_true( in_array( 'SpeedyCache (pages + combined CSS/JS)', get_option( Cache::OPT_LAST )['targets'], true ) );

		// Every later request on the same version does nothing.
		Cache::on_version_change();
		Cache::flush_pending();
		assert_same( 1, count( \SpeedyCache\Delete::$calls ) );
	} );

	test( 'an event change purges SpeedyCache pages but leaves its assets alone', function () {
		\SpeedyCache\Delete::$calls = [];
		update_option( Cache::OPT_PURGED_VER, GASF_EVENTS_VERSION );

		Cache::schedule();
		Cache::flush_pending();
		assert_same( [ [] ], \SpeedyCache\Delete::$calls );
	} );

	test( 'the feed-run action passes its stats array, which is not an asset purge', function () {
		\SpeedyCache\Delete::$calls = [];
		Cache::schedule( [ 'created' => 3 ] );
		Cache::flush_pending();
		assert_same( [ [] ], \SpeedyCache\Delete::$calls );
	} );

	test( 'a manual purge from Events → Health clears assets too', function () {
		\SpeedyCache\Delete::$calls = [];
		Cache::purge( true );
		assert_same( [ [ 'minified' => true ] ], \SpeedyCache\Delete::$calls );
	} );
}
