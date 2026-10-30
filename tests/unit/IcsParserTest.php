<?php
/**
 * ICS_Parser — inbound .ics feeds, incl. recurrence expansion for the GASF calendar.
 *
 * @package GASF_Events
 */

use GASF_Events\ICS_Parser;
use GASF_Events\Meta;

$feed = static function (): array {
	$ics = "BEGIN:VCALENDAR\r\n"
		. "BEGIN:VEVENT\r\nUID:weekly@x\r\nSUMMARY:Euchre\r\nDTSTART;TZID=America/New_York:20261105T190000\r\nDTEND;TZID=America/New_York:20261105T220000\r\nRRULE:FREQ=WEEKLY;BYDAY=TH;UNTIL=20261231\r\nEXDATE;TZID=America/New_York:20261126T190000\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:weekly@x\r\nRECURRENCE-ID;TZID=America/New_York:20261112T190000\r\nSUMMARY:Euchre (moved)\r\nDTSTART;TZID=America/New_York:20261113T190000\r\nDTEND;TZID=America/New_York:20261113T220000\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:monthly@x\r\nSUMMARY:Board\r\nDTSTART:20261110T180000\r\nDTEND:20261110T200000\r\nRRULE:FREQ=MONTHLY;BYDAY=2TU;COUNT=3\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:single@x\r\nSUMMARY:Gala\r\nDTSTART:20261205T190000Z\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:allday@x\r\nSUMMARY:Clean\r\n up day\r\nDTSTART;VALUE=DATE:20261107\r\nDTEND;VALUE=DATE:20261108\r\nRRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=3\r\nEND:VEVENT\r\n"
		. "END:VCALENDAR\r\n";
	$norm = array_values( array_filter( array_map( [ ICS_Parser::class, 'to_norm' ], ICS_Parser::parse( $ics ) ) ) );
	$out  = ICS_Parser::expand( $norm, Meta::local_to_ts( '2026-11-01 00:00:00' ), Meta::local_to_ts( '2027-11-01 00:00:00' ) );
	$by   = [];
	foreach ( $out as $e ) {
		$by[ $e['uid'] ] = $e;
	}
	return [ $out, $by ];
};

test( 'parse() unfolds continuation lines', function () use ( $feed ) {
	[ , $by ] = $feed();
	assert_same( 'Cleanup day', $by['allday@x@20261107T000000']['title'] );
} );

test( 'weekly rule with UNTIL, minus one EXDATE', function () use ( $feed ) {
	[ $out, $by ] = $feed();
	$weekly = array_filter( $out, static fn( $e ) => str_starts_with( $e['uid'], 'weekly@x@' ) );
	assert_same( 8, count( $weekly ), '9 Thursdays from Nov 5 to Dec 31, less the excluded Nov 26' );
	assert_false( isset( $by['weekly@x@20261126T190000'] ), 'EXDATE removed' );
} );

test( 'RECURRENCE-ID replaces its date with the moved instance', function () use ( $feed ) {
	[ , $by ] = $feed();
	$e = $by['weekly@x@20261112T190000'] ?? null;
	assert_true( null !== $e, 'the override keeps the slot uid' );
	assert_same( 'Euchre (moved)', $e['title'] );
	assert_same( '2026-11-13 19:00:00', $e['start'] );
} );

test( 'instances keep the duration and share a series id', function () use ( $feed ) {
	[ , $by ] = $feed();
	assert_same( '2026-11-05 22:00:00', $by['weekly@x@20261105T190000']['end'] );
	assert_same( 'ics-weekly@x', $by['weekly@x@20261105T190000']['series_id'] );
	assert_true( $by['weekly@x@20261105T190000']['is_series'] );
} );

test( 'wall-clock time is kept across daylight saving', function () use ( $feed ) {
	[ , $by ] = $feed();
	assert_same( '2026-12-03 19:00:00', $by['weekly@x@20261203T190000']['start'] );
} );

test( 'monthly 2nd Tuesday, COUNT=3; floating time is read as site-local, not UTC', function () use ( $feed ) {
	[ $out ] = $feed();
	$starts = array_values( array_map( static fn( $e ) => $e['start'], array_filter( $out, static fn( $e ) => str_starts_with( $e['uid'], 'monthly@x@' ) ) ) );
	assert_same( [ '2026-11-10 18:00:00', '2026-12-08 18:00:00', '2027-01-12 18:00:00' ], $starts );
} );

test( 'a non-recurring event passes through untouched; UTC is converted to site time', function () use ( $feed ) {
	[ , $by ] = $feed();
	assert_same( '2026-12-05 14:00:00', $by['single@x']['start'] );
	assert_same( '', $by['single@x']['series_id'] );
} );

test( 'all-day, every two weeks; the exclusive DTEND becomes an inclusive end', function () use ( $feed ) {
	[ $out ] = $feed();
	$ad = array_values( array_filter( $out, static fn( $e ) => str_starts_with( $e['uid'], 'allday@x@' ) ) );
	assert_same( 3, count( $ad ) );
	assert_same( '2026-11-21 00:00:00', $ad[1]['start'] );
	assert_same( '2026-11-21 23:59:59', $ad[1]['end'] );
	assert_true( $ad[1]['all_day'] );
} );

test( 'expansion stops at the window', function () {
	$norm = ICS_Parser::to_norm( [ 'uid' => 'w@x', 'summary' => 'W', 'dtstart' => '2026-11-05T19:00:00', 'dtstart_allday' => false, 'dtstart_tzid' => 'America/New_York', 'rrule' => 'FREQ=DAILY' ] );
	$out  = ICS_Parser::expand( [ $norm ], Meta::local_to_ts( '2026-11-05 00:00:00' ), Meta::local_to_ts( '2026-11-08 23:59:59' ) );
	assert_same( 4, count( $out ) );
} );
