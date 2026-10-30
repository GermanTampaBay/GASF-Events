<?php
/**
 * The built-in FC Bayern recurring hero rule (recurring-heroes.php, v0.37.1).
 *
 * @package GASF_Events
 */

const GASF_T_RULES = 'gasf_hero_recurring';

test( 'a fresh site gets the rule, exactly as asked for', function () {
	assert_same( 'added', gasf_hero_recurring_seed_fcbayern() );
	$r = get_option( GASF_T_RULES )[0];
	assert_same( 'FC Bayern', $r['title'] );
	assert_same( 'contains', $r['match'] );
	assert_same( 0, (int) $r['image_id'], 'no image of its own: each game uses its cover' );
	assert_same( "Tampa Bay's home for the official FC Bayern München fan club.", $r['caption'] );
	assert_same( 'Click for more info', $r['button_label'] );
	assert_same( 'https://germantampabay.com/fcbmc/', $r['button_url'] );
	assert_same( 2, (int) $r['lead_days'] );
} );

test( 'it runs once: no duplicate, and a deletion is respected', function () {
	gasf_hero_recurring_seed_fcbayern();
	assert_same( 'skipped', gasf_hero_recurring_seed_fcbayern() );
	assert_same( 1, count( get_option( GASF_T_RULES ) ) );
	update_option( GASF_T_RULES, [] );
	assert_same( 'skipped', gasf_hero_recurring_seed_fcbayern() );
	assert_same( [], get_option( GASF_T_RULES ) );
} );

test( 'an existing "contains" FC Bayern rule is reused, filling only its blanks', function () {
	update_option( GASF_T_RULES, [
		[ 'id' => 'rh_x', 'title' => 'Euchre Night', 'enabled' => 1 ],
		[ 'id' => 'rh_y', 'title' => 'FC Bayern v', 'match' => 'contains', 'enabled' => 1, 'caption' => '', 'button_label' => 'Tickets', 'button_url' => '' ],
	] );
	assert_same( 'filled', gasf_hero_recurring_seed_fcbayern() );
	$d = get_option( GASF_T_RULES );
	assert_same( 2, count( $d ) );
	assert_same( 'FC Bayern v', $d[1]['title'], 'match text left alone' );
	assert_same( 'https://germantampabay.com/fcbmc/', $d[1]['button_url'] );
	assert_same( 'Tickets', $d[1]['button_label'], 'a label somebody typed is kept' );
	assert_false( isset( $d[0]['caption'] ), 'unrelated rule untouched' );
} );

test( 'an exact rule for one game does not count as the series rule', function () {
	update_option( GASF_T_RULES, [ [ 'id' => 'rh_z', 'title' => 'FC Bayern v Dortmund', 'enabled' => 1 ] ] );
	assert_same( 'added', gasf_hero_recurring_seed_fcbayern() );
	assert_same( 2, count( get_option( GASF_T_RULES ) ) );
} );

test( 'matching is a LIKE on the title', function () {
	[ $op, $val ] = gasf_hero_recurring_title_condition( 'FC Bayern', 'contains' );
	assert_same( 'LIKE', $op );
	assert_same( '%FC Bayern%', $val );
} );

test( 'a game with no cover is not a valid hero; one with a cover is', function () {
	$def  = gasf_hero_recurring_fcb_defaults() + [ 'id' => 'rh_fcbayern', '_lead_secs' => 2 * DAY_IN_SECONDS ];
	$game = (object) [ 'id' => 501, 'title' => 'FC Bayern v Leipzig', 'start_ts' => 2000000000, 'end_ts' => 2000007200 ];
	assert_same( null, gasf_hero_recurring_entry( $def, $game ) );
	$GLOBALS['gasf_thumbs'][501] = 9001;
	$e = gasf_hero_recurring_entry( $def, $game );
	assert_same( 9001, $e['image_id'] );
	assert_same( 'https://example.test/events/501/', $e['image_url'] );
	assert_same( 2000000000 - 2 * DAY_IN_SECONDS, $e['activate_at'] );
	assert_same( 2000007200, $e['_expire_at'] );
	assert_same( 'Click for more info', $e['button_label'] );
} );
