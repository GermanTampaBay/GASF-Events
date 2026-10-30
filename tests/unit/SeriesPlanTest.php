<?php
/**
 * Series::plan() — what a master save does to its dates.
 *
 * @package GASF_Events
 */

use GASF_Events\Series;

$now  = 1_800_000_000;                 // a fixed "now" so the tests never drift
$day  = 86400;
$rule = [                              // slot => start ts, the rule's own dates
	'2027-01-07' => $now + 7 * $day,
	'2027-01-14' => $now + 14 * $day,
	'2027-01-21' => $now + 21 * $day,
];
$m = static fn( string $slot, int $start_ts, bool $custom = false ) => [ 'slot' => $slot, 'start_ts' => $start_ts, 'customized' => $custom ];

test( 'a new series creates every date', function () use ( $rule, $now ) {
	$p = Series::plan( $rule, [], $now, false );
	assert_same( [ '2027-01-07', '2027-01-14', '2027-01-21' ], $p['create'] );
	assert_same( [], $p['apply'] );
	assert_same( [], $p['orphans'] );
} );

test( 'saving again creates nothing and changes nothing without "apply to future"', function () use ( $rule, $now, $m, $day ) {
	$members = [ 11 => $m( '2027-01-07', $now + 7 * $day ), 12 => $m( '2027-01-14', $now + 14 * $day ), 13 => $m( '2027-01-21', $now + 21 * $day ) ];
	$p = Series::plan( $rule, $members, $now, false );
	assert_same( [], $p['create'] );
	assert_same( [], $p['apply'] );
	assert_same( [], $p['orphans'] );
} );

test( '"apply to future" re-stamps future dates but never past ones', function () use ( $now, $m, $day ) {
	$rule    = [ '2026-12-31' => $now - $day, '2027-01-07' => $now + 7 * $day ];
	$members = [ 10 => $m( '2026-12-31', $now - $day ), 11 => $m( '2027-01-07', $now + 7 * $day ) ];
	$p = Series::plan( $rule, $members, $now, true );
	assert_same( [ 11 => '2027-01-07' ], $p['apply'] );
} );

test( '"apply to future" skips a date edited by hand', function () use ( $rule, $now, $m, $day ) {
	$members = [ 11 => $m( '2027-01-07', $now + 7 * $day ), 12 => $m( '2027-01-14', $now + 14 * $day, true ), 13 => $m( '2027-01-21', $now + 21 * $day ) ];
	$p = Series::plan( $rule, $members, $now, true );
	assert_same( [ 11 => '2027-01-07', 13 => '2027-01-21' ], $p['apply'] );
} );

test( 'a date moved by hand still holds its slot, so no duplicate is created', function () use ( $rule, $now, $m, $day ) {
	// The 14th was moved to the 15th. Before v0.38.0 the series matched by the
	// CURRENT date and re-created a second event on the 14th.
	$members = [ 11 => $m( '2027-01-07', $now + 7 * $day ), 12 => $m( '2027-01-14', $now + 15 * $day, true ), 13 => $m( '2027-01-21', $now + 21 * $day ) ];
	$p = Series::plan( $rule, $members, $now, false );
	assert_same( [], $p['create'] );
	assert_same( [], $p['orphans'] );
} );

test( 'shortening the rule turns future untouched dates into orphans, hand-edited ones are kept', function () use ( $now, $m, $day ) {
	$rule    = [ '2027-01-07' => $now + 7 * $day ];   // was three dates, now one
	$members = [ 11 => $m( '2027-01-07', $now + 7 * $day ), 12 => $m( '2027-01-14', $now + 14 * $day, true ), 13 => $m( '2027-01-21', $now + 21 * $day ) ];
	$p = Series::plan( $rule, $members, $now, false );
	assert_same( [ 13 ], $p['orphans'] );
	assert_same( [ 12 ], $p['kept_custom'] );
} );

test( 'past dates outside the rule are history, never orphans', function () use ( $now, $m, $day ) {
	$rule    = [ '2027-01-07' => $now + 7 * $day ];
	$members = [ 9 => $m( '2026-12-24', $now - 7 * $day ), 11 => $m( '2027-01-07', $now + 7 * $day ) ];
	$p = Series::plan( $rule, $members, $now, false );
	assert_same( [], $p['orphans'] );
	assert_same( [], $p['kept_custom'] );
} );

test( 'moving the series to another weekday: new dates created, old ones offered as orphans', function () use ( $now, $m, $day ) {
	$rule    = [ '2027-01-08' => $now + 8 * $day, '2027-01-15' => $now + 15 * $day ]; // Thursdays → Fridays
	$members = [ 11 => $m( '2027-01-07', $now + 7 * $day ), 12 => $m( '2027-01-14', $now + 14 * $day ) ];
	$p = Series::plan( $rule, $members, $now, true );
	assert_same( [ '2027-01-08', '2027-01-15' ], $p['create'] );
	assert_same( [ 11, 12 ], $p['orphans'] );
	assert_same( [], $p['apply'] );
} );

test( 'two events on one slot (left by the old bug): the original keeps it, the later one is an orphan', function () use ( $rule, $now, $m, $day ) {
	$members = [ 20 => $m( '2027-01-14', $now + 14 * $day ), 12 => $m( '2027-01-14', $now + 14 * $day ), 11 => $m( '2027-01-07', $now + 7 * $day ), 13 => $m( '2027-01-21', $now + 21 * $day ) ];
	$p = Series::plan( $rule, $members, $now, false );
	assert_same( [ 20 ], $p['orphans'] );
	assert_same( [], $p['create'] );
} );
