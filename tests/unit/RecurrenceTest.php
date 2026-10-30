<?php
/**
 * Recurrence::occurrences() — the "Repeats" rule in the editor.
 *
 * @package GASF_Events
 */

use GASF_Events\Recurrence;

$ny  = new DateTimeZone( 'America/New_York' );
$fmt = static fn( array $ds ) => array_map( static fn( $d ) => $d->format( 'Y-m-d H:i' ), $ds );

test( 'weekly, by count, includes the first date', function () use ( $ny, $fmt ) {
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-11-05 19:00', $ny ), 'weekly', '', 3 );
	assert_same( [ '2026-11-05 19:00', '2026-11-12 19:00', '2026-11-19 19:00' ], $fmt( $ds ) );
} );

test( 'weekly keeps the wall-clock time across the end of daylight saving', function () use ( $ny, $fmt ) {
	// DST ends 2026-11-01 in New York.
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-10-29 19:00', $ny ), 'weekly', '', 2 );
	assert_same( [ '2026-10-29 19:00', '2026-11-05 19:00' ], $fmt( $ds ) );
	assert_same( 7 * 86400 + 3600, $ds[1]->getTimestamp() - $ds[0]->getTimestamp(), 'the extra hour is real time, not a moved event' );
} );

test( 'every two weeks, until a date (inclusive)', function () use ( $ny, $fmt ) {
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-11-07 12:00', $ny ), 'biweekly', '2026-12-05' );
	assert_same( [ '2026-11-07 12:00', '2026-11-21 12:00', '2026-12-05 12:00' ], $fmt( $ds ) );
} );

test( 'monthly keeps the same weekday position (2nd Thursday)', function () use ( $ny, $fmt ) {
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-01-08 18:30', $ny ), 'monthly', '', 3 );
	assert_same( [ '2026-01-08 18:30', '2026-02-12 18:30', '2026-03-12 18:30' ], $fmt( $ds ) );
} );

test( 'monthly on a 5th weekday skips the months that have none', function () use ( $ny, $fmt ) {
	// 2026-01-29 is the 5th Thursday; February and March 2026 have only four.
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-01-29 19:00', $ny ), 'monthly', '', 2 );
	assert_same( [ '2026-01-29 19:00', '2026-04-30 19:00' ], $fmt( $ds ) );
} );

test( 'no end given: weekly for one year', function () use ( $ny ) {
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-11-05 19:00', $ny ), 'weekly' );
	assert_same( 53, count( $ds ) );
} );

test( 'a count above the cap is clamped to REPEAT_MAX', function () use ( $ny ) {
	$ds = Recurrence::occurrences( new DateTimeImmutable( '2026-11-05 19:00', $ny ), 'weekly', '2030-01-01', 500 );
	assert_same( GASF_Events\Meta::REPEAT_MAX, count( $ds ) );
} );

test( 'an unknown frequency is just the first date', function () use ( $ny, $fmt ) {
	assert_same( [ '2026-11-05 19:00' ], $fmt( Recurrence::occurrences( new DateTimeImmutable( '2026-11-05 19:00', $ny ), 'daily' ) ) );
} );

test( 'after_first() drops the anchor', function () use ( $ny, $fmt ) {
	assert_same( [ '2026-11-12 19:00' ], $fmt( Recurrence::after_first( new DateTimeImmutable( '2026-11-05 19:00', $ny ), 'weekly', '', 2 ) ) );
} );
