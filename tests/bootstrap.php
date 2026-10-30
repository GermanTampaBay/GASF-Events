<?php
/**
 * Just enough WordPress for the unit tests: an in-memory options table, a
 * site timezone, no-op hooks and a $wpdb that records the SQL it was asked to
 * prepare. Each stub is defined only if missing, and gasf_test_reset() puts
 * everything back between tests.
 *
 * Only pure plugin logic is loaded. Anything that needs posts, users or HTTP
 * belongs in tests/integration/run.php instead.
 *
 * @package GASF_Events
 */

namespace {

	error_reporting( E_ALL );
	set_error_handler( static function ( int $no, string $msg, string $file, int $line ): bool {
		throw new \ErrorException( $msg, 0, $no, $file, $line );
	} );

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'YEAR_IN_SECONDS', 31536000 );
	define( 'GASF_EVENTS_DIR', dirname( __DIR__ ) . '/' );
	define( 'GASF_EVENTS_CPT', 'gasf_event' );
	date_default_timezone_set( 'UTC' ); // what WordPress forces; the SITE zone comes from wp_timezone()

	/* ---- assertions ---------------------------------------------------- */

	final class GasfAssertionFailed extends \Exception {}

	function test( string $name, callable $fn ): void {
		$GLOBALS['gasf_tests'][] = [ $name, $fn ];
	}

	function assert_same( $expected, $actual, string $msg = '' ): void {
		if ( $expected !== $actual ) {
			throw new GasfAssertionFailed( ( '' !== $msg ? $msg . "\n" : '' ) . 'expected ' . var_export( $expected, true ) . "\n     got " . var_export( $actual, true ) );
		}
	}

	function assert_true( $cond, string $msg = 'expected true' ): void {
		if ( true !== $cond ) {
			throw new GasfAssertionFailed( $msg );
		}
	}

	function assert_false( $cond, string $msg = 'expected false' ): void {
		if ( false !== $cond ) {
			throw new GasfAssertionFailed( $msg );
		}
	}

	/* ---- WordPress stand-ins ------------------------------------------- */

	$GLOBALS['gasf_opts']   = [];
	$GLOBALS['gasf_thumbs'] = [];
	$GLOBALS['gasf_purges'] = [];

	function gasf_test_reset(): void {
		$GLOBALS['gasf_opts']   = [];
		$GLOBALS['gasf_thumbs'] = [];
		$GLOBALS['gasf_purges'] = [];
		$GLOBALS['wpdb']        = new GasfFakeDb();
	}

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['gasf_opts'] ) ? $GLOBALS['gasf_opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['gasf_opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['gasf_opts'][ $k ] ); return true; }
	function add_action( ...$a ) { return true; }
	function add_filter( ...$a ) { return true; }
	function add_shortcode( ...$a ) { return true; }
	function apply_filters( $tag, $value, ...$a ) { return $value; }
	function do_action( ...$a ) {}
	function wp_next_scheduled( ...$a ) { return 1; }
	function wp_schedule_event( ...$a ) { return true; }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'America/New_York' ); }
	function wp_timezone_string(): string { return 'America/New_York'; }
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function __( $s, $d = null ) { return $s; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function get_post_thumbnail_id( $id ) { return $GLOBALS['gasf_thumbs'][ $id ] ?? 0; }
	function get_permalink( $id ) { return "https://example.test/events/{$id}/"; }

	// heroes.php provides these on a real site; recurring-heroes.php needs them to exist.
	function gasf_hero_active() { return null; }
	function gasf_hero_schedule_purge( $ts ) { $GLOBALS['gasf_purges'][] = (int) $ts; }

	/** Records prepared SQL; returns whatever a test put in ->row. */
	final class GasfFakeDb {
		public $posts    = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public $options  = 'wp_options';
		public $row      = null;
		public $last     = '';
		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
		public function prepare( $q, ...$a ) {
			$this->last = vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $q ), $a );
			return $this->last;
		}
		public function get_row( $q ) { return $this->row; }
		public function get_col( $q ) { return []; }
		public function get_var( $q ) { return null; }
		public function query( $q ) { return 0; }
	}
	$GLOBALS['wpdb'] = new GasfFakeDb();
}

namespace {
	// Plugin code under test. Order matters only where one file uses another's
	// constants at load time.
	$dir = GASF_EVENTS_DIR . 'includes/';
	require_once $dir . 'class-meta.php';
	require_once $dir . 'class-recurrence.php';
	require_once $dir . 'class-ics-parser.php';
	require_once $dir . 'class-event-merge.php';
	require_once $dir . 'class-event.php';
	require_once $dir . 'class-series.php';
	require_once $dir . 'recurring-heroes.php';
}
