<?php
/**
 * Repository sanity: things that broke before and were invisible until a
 * visitor noticed. Each test names the incident it guards against.
 *
 * @package GASF_Events
 */

$root = GASF_EVENTS_DIR;

test( 'every asset the plugin registers is in the repository', function () use ( $root ) {
	// v0.33.1: assets/js/vendor/alpine.min.js was enqueued for several releases
	// but git-ignored, so every zip install served a 404 and the signage froze.
	$missing = [];
	foreach ( glob( $root . 'includes/*.php' ) as $f ) {
		preg_match_all( "/GASF_EVENTS_URL\s*\.\s*'([^']+\.(?:js|css|svg|png|jpg|woff2))'/", (string) file_get_contents( $f ), $m );
		foreach ( $m[1] as $rel ) {
			if ( ! is_file( $root . $rel ) ) {
				$missing[] = basename( $f ) . ' → ' . $rel;
			}
		}
	}
	assert_same( [], $missing );
} );

test( 'every file class-plugin.php requires exists', function () use ( $root ) {
	preg_match_all( "/require_once \\\$dir \. '([^']+)'/", (string) file_get_contents( $root . 'includes/class-plugin.php' ), $m );
	assert_true( count( $m[1] ) > 20, 'found the require list' );
	$missing = array_values( array_filter( $m[1], static fn( $rel ) => ! is_file( $root . 'includes/' . $rel ) ) );
	assert_same( [], $missing );
} );

test( 'the Version header is a plain version number', function () use ( $root ) {
	// The updater and the release job both read it; anything odd means no release.
	assert_same( 1, preg_match( '/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$/m', (string) file_get_contents( $root . 'gasf-events.php' ) ) );
} );

test( 'no PHP file starts with output before <?php', function () use ( $root ) {
	// Whitespace before <?php in an included file breaks every redirect and
	// download the plugin sends ("headers already sent").
	$bad = [];
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = (string) $f;
		if ( str_ends_with( $p, '.php' ) && ! str_contains( $p, '/.git/' ) && ! str_contains( $p, '/vendor/' ) && ! str_starts_with( (string) file_get_contents( $p, false, null, 0, 5 ), '<?php' ) ) {
			$bad[] = substr( $p, strlen( $root ) );
		}
	}
	assert_same( [], $bad );
} );
