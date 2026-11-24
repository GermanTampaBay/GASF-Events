<?php
/**
 * FB_Client::normalize() — recurring expansion and the past-occurrence cutoff.
 *
 * Regression: the Graph query used to send `since`, which Facebook applies to a
 * recurring event's PARENT start. Once Biergarten's first date (Oct 31) was
 * older than the cutoff, the whole weekly series vanished from the response and
 * prune_missing() unpublished Nov 28 - Dec 26. The cutoff now lives here, per
 * occurrence, so a long-running series keeps its future dates.
 *
 * @package GASF_Events
 */

use GASF_Events\FB_Client;

$biergarten = static function (): array {
	$times = [];
	foreach ( [ '10-31', '11-07', '11-14', '11-21', '11-28', '12-05', '12-12', '12-19', '12-26' ] as $i => $md ) {
		$times[] = [
			'id'         => 'occ' . $i,
			'start_time' => "2026-{$md}T18:00:00-0500",
			'end_time'   => "2026-{$md}T22:00:00-0500",
		];
	}
	return [
		'id'          => '2124691378103866',
		'name'        => 'Biergarten',
		'start_time'  => '2026-10-31T18:00:00-0400',
		'end_time'    => '2026-10-31T22:00:00-0400',
		'event_times' => $times,
	];
};

test( 'recurring series keeps only occurrences that have not ended before the cutoff', function () use ( $biergarten ) {
	$cutoff = ( new DateTimeImmutable( '2026-11-22T09:00:00-0500' ) )->getTimestamp();
	$out    = FB_Client::normalize( $biergarten(), 'acct', $cutoff );
	assert_true( is_array( $out ) && $out['recurring'], 'still a recurring event' );
	$starts = array_map( static fn( $o ) => $o['start'], $out['occurrences'] );
	assert_same( [ '2026-11-28 18:00:00', '2026-12-05 18:00:00', '2026-12-12 18:00:00', '2026-12-19 18:00:00', '2026-12-26 18:00:00' ], $starts );
	assert_same( 'fb-2124691378103866', $out['occurrences'][0]['series_id'] );
	assert_same( 'occ4', $out['occurrences'][0]['fb_id'] );
} );

test( 'a series whose first date is long past is NOT dropped (the old since= bug)', function () use ( $biergarten ) {
	$cutoff = ( new DateTimeImmutable( '2026-12-20T00:00:00-0500' ) )->getTimestamp();
	$out    = FB_Client::normalize( $biergarten(), 'acct', $cutoff );
	assert_same( 1, count( $out['occurrences'] ), 'Dec 26 survives even though the parent started Oct 31' );
} );

test( 'occurrence in progress at the cutoff is kept', function () use ( $biergarten ) {
	$cutoff = ( new DateTimeImmutable( '2026-11-28T20:00:00-0500' ) )->getTimestamp();
	$out    = FB_Client::normalize( $biergarten(), 'acct', $cutoff );
	assert_same( '2026-11-28 18:00:00', $out['occurrences'][0]['start'] );
} );

test( 'series with every date past returns null', function () use ( $biergarten ) {
	$cutoff = ( new DateTimeImmutable( '2027-01-01T00:00:00-0500' ) )->getTimestamp();
	assert_same( null, FB_Client::normalize( $biergarten(), 'acct', $cutoff ) );
} );

test( 'single event: kept when current, dropped when ended before the cutoff, kept with no cutoff', function () {
	$raw    = [ 'id' => '1', 'name' => 'Gala', 'start_time' => '2026-12-05T19:00:00-0500', 'end_time' => '2026-12-05T23:00:00-0500' ];
	$before = ( new DateTimeImmutable( '2026-12-01T00:00:00-0500' ) )->getTimestamp();
	$after  = ( new DateTimeImmutable( '2026-12-10T00:00:00-0500' ) )->getTimestamp();
	assert_same( '2026-12-05 19:00:00', FB_Client::normalize( $raw, 'a', $before )['occurrences'][0]['start'] );
	assert_same( null, FB_Client::normalize( $raw, 'a', $after ) );
	assert_same( 1, count( FB_Client::normalize( $raw, 'a' )['occurrences'] ) );
} );

test( 'single event with no end_time falls back to start_time for the cutoff', function () {
	$raw = [ 'id' => '2', 'name' => 'Quiz', 'start_time' => '2026-12-05T19:00:00-0500' ];
	assert_same( null, FB_Client::normalize( $raw, 'a', ( new DateTimeImmutable( '2026-12-06T00:00:00-0500' ) )->getTimestamp() ) );
	assert_same( '', FB_Client::normalize( $raw, 'a', ( new DateTimeImmutable( '2026-12-04T00:00:00-0500' ) )->getTimestamp() )['occurrences'][0]['end'] );
} );
