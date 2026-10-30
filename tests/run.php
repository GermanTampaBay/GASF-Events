<?php
/**
 * Unit test runner — no Composer, no PHPUnit, nothing to install.
 *
 *   php tests/run.php            run every tests/unit/*Test.php
 *   php tests/run.php Series     only files whose name contains "Series"
 *
 * The plugin deliberately has no build step, so its tests do not get one
 * either: anything that runs PHP 8.0+ runs these, including CI on every PHP
 * version the plugin supports. They cover the logic that can be checked
 * without a database (recurrence maths, ICS parsing, series planning, merge
 * matching, the FC Bayern hero rule, repository sanity). The behaviour that
 * needs real WordPress is in tests/integration/run.php, which CI runs in a
 * throwaway WordPress (see .github/workflows/ci.yml).
 *
 * Warnings, notices and deprecations FAIL a test: a deprecation on PHP 8.4 is
 * tomorrow's fatal error, and this is where it gets noticed first.
 *
 * @package GASF_Events
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files  = glob( __DIR__ . '/unit/*Test.php' ) ?: [];
sort( $files );

$passed = 0;
$failed = [];
foreach ( $files as $file ) {
	if ( '' !== $filter && false === stripos( basename( $file ), $filter ) ) {
		continue;
	}
	$GLOBALS['gasf_tests'] = [];
	require $file;
	echo basename( $file, '.php' ), "\n";
	foreach ( $GLOBALS['gasf_tests'] as [ $name, $fn ] ) {
		gasf_test_reset();
		try {
			$fn();
			$passed++;
			echo "  ✓ {$name}\n";
		} catch ( \Throwable $e ) {
			$where    = basename( $e->getFile() ) . ':' . $e->getLine();
			$failed[] = basename( $file ) . " › {$name}";
			echo "  ✗ {$name}\n      " . str_replace( "\n", "\n      ", $e->getMessage() ) . "\n      at {$where}\n";
		}
	}
}

echo "\n", $passed, ' passed, ', count( $failed ), " failed\n";
if ( ! $passed && ! $failed ) {
	echo "No tests ran.\n";
	exit( 1 );
}
exit( $failed ? 1 : 0 );
