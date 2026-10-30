<?php
/**
 * Event_Merge — collapsing the same event arriving from several feeds.
 * The cases are the live Oktoberfest overlap documented in the class.
 *
 * @package GASF_Events
 */

use GASF_Events\Event_Merge;

$ev = static fn( string $title, string $start, string $end, string $desc = '' ) => [ 'title' => $title, 'start' => $start, 'end' => $end, 'description' => $desc ];

test( 'same title, start and end: one event', function () use ( $ev ) {
	assert_true( Event_Merge::same_event( $ev( 'Oktoberfest', '2026-10-03 12:00:00', '2026-10-03 22:00:00' ), $ev( 'Oktoberfest GASF', '2026-10-03 12:00:00', '2026-10-03 22:00:00' ) ) );
} );

test( 'same start, different end: a different festival (the end time is the discriminator)', function () use ( $ev ) {
	assert_false( Event_Merge::same_event( $ev( 'Oktoberfest', '2026-10-03 12:00:00', '2026-10-03 22:00:00' ), $ev( 'Oktoberfest Tampa', '2026-10-03 17:00:00', '2026-10-03 19:00:00' ) ) );
	assert_false( Event_Merge::same_event( $ev( 'Oktoberfest', '2026-10-03 12:00:00', '2026-10-03 22:00:00' ), $ev( "Wellen Park O'fest", '2026-10-03 12:00:00', '2026-10-03 14:00:00' ) ) );
} );

test( 'times within 30 minutes still match; an hour off does not', function () use ( $ev ) {
	$a = $ev( 'Biergarten', '2026-11-07 18:00:00', '2026-11-07 22:00:00' );
	assert_true( Event_Merge::same_event( $a, $ev( 'Biergarten', '2026-11-07 18:30:00', '2026-11-07 22:00:00' ) ) );
	assert_false( Event_Merge::same_event( $a, $ev( 'Biergarten', '2026-11-07 19:00:00', '2026-11-07 22:00:00' ) ) );
} );

test( 'a missing end never widens a match', function () use ( $ev ) {
	assert_false( Event_Merge::same_event( $ev( 'Biergarten', '2026-11-07 18:00:00', '' ), $ev( 'Biergarten', '2026-11-07 18:00:00', '2026-11-07 22:00:00' ) ) );
} );

test( 'titles: punctuation and case ignored, typos tolerated, unrelated titles rejected', function () {
	assert_true( Event_Merge::titles_match( 'Oktoberfest — GASF', 'oktoberfest gasf' ) );
	assert_true( Event_Merge::titles_match( 'Oktoberfest 2026', 'Oktoberfest 2O26' ) );
	assert_false( Event_Merge::titles_match( 'Euchre Night', 'Krampus Meetup' ) );
} );

test( 'collapse(): the earlier (definitive) feed wins and absorbs the description, with attribution', function () use ( $ev ) {
	$groups = [
		[ 'feed' => [ 'label' => 'GASF' ], 'events' => [ $ev( 'Oktoberfest', '2026-10-03 12:00:00', '2026-10-03 22:00:00', 'Music all day.' ) ] ],
		[ 'feed' => [ 'label' => 'GCESV' ], 'events' => [
			$ev( 'Oktoberfest GASF', '2026-10-03 12:00:00', '2026-10-03 22:00:00', 'Parking on 66th.' ),
			$ev( 'Oktoberfest Tampa', '2026-10-03 17:00:00', '2026-10-03 19:00:00' ),
		] ],
	];
	$r = Event_Merge::collapse( $groups );
	assert_same( 1, count( $r['merges'] ) );
	assert_same( [ 'Oktoberfest Tampa' ], array_column( $r['groups'][1]['events'], 'title' ) );
	assert_same( "Music all day.\n\n— GCESV —\nParking on 66th.", $r['groups'][0]['events'][0]['description'] );
} );
