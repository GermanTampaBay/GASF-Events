<?php
/**
 * Integration tests — run INSIDE a real WordPress with the plugin active:
 *
 *   wp eval-file wp-content/plugins/GASF-Events/tests/integration/run.php
 *
 * CI runs this in a throwaway WordPress (@wordpress/env, see
 * .github/workflows/ci.yml). It creates events, so never point it at the
 * live site. It checks what the unit tests cannot: that the plugin loads and
 * activates without errors, that real posts and meta flow through the REST
 * feed (fields, filters, ETag/304, the changes feed), that series generation,
 * status mirroring, hand-edit protection and orphan detection work against
 * the database, and that the page/feed builders produce output.
 *
 * @package GASF_Events
 */

use GASF_Events\Meta;
use GASF_Events\Event;
use GASF_Events\Series;

if ( ! defined( 'WP_CLI' ) || ! class_exists( '\GASF_Events\Plugin' ) ) {
	fwrite( STDERR, "Run with: wp eval-file …/tests/integration/run.php (plugin active)\n" );
	exit( 1 );
}

$GLOBALS['gasf_ci'] = [ 'pass' => 0, 'fail' => [] ];

function gasf_ci_check( string $name, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		$GLOBALS['gasf_ci']['pass']++;
		WP_CLI::log( "  ✓ {$name}" );
	} else {
		$GLOBALS['gasf_ci']['fail'][] = $name;
		WP_CLI::log( "  ✗ {$name}" . ( '' !== $detail ? "\n      {$detail}" : '' ) );
	}
}

function gasf_ci_event( string $title, string $start, string $end, array $meta = [], string $status = 'publish' ): int {
	$id = wp_insert_post( [ 'post_type' => GASF_EVENTS_CPT, 'post_status' => $status, 'post_title' => $title, 'post_content' => 'CI event.' ], true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'could not create an event: ' . $id->get_error_message() );
	}
	update_post_meta( $id, Meta::START, $start );
	update_post_meta( $id, Meta::END, $end );
	update_post_meta( $id, Meta::SOURCE, 'manual' );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	Meta::recompute_timestamps( (int) $id );
	return (int) $id;
}

function gasf_ci_get( string $route, array $params = [] ): WP_REST_Response {
	$req = new WP_REST_Request( 'GET', $route );
	$req->set_query_params( $params );
	return rest_ensure_response( rest_do_request( $req ) );
}

$tz  = wp_timezone();
$at  = static fn( string $rel ) => ( new DateTimeImmutable( $rel, $tz ) )->format( 'Y-m-d H:i:s' );

/* ---- 1. Loaded and wired ------------------------------------------- */
WP_CLI::log( 'Plugin' );
gasf_ci_check( 'gasf_event post type registered', post_type_exists( GASF_EVENTS_CPT ) );
$routes = rest_get_server()->get_routes();
foreach ( [ '/gasf-events/v1/events', '/gasf-events/v1/events/changes', '/gasf-events/v1/view' ] as $r ) {
	gasf_ci_check( "REST route {$r}", isset( $routes[ $r ] ) );
}

/* ---- 2. REST feed --------------------------------------------------- */
WP_CLI::log( 'REST feed' );
$a = gasf_ci_event( 'CI Biergarten', $at( '+3 days 19:00' ), $at( '+3 days 22:00' ), [ Meta::FEATURED => 1, Meta::PRIORITY => 40, Meta::SIGN_TEXT => 'Sat 7 pm' ] );
$b = gasf_ci_event( 'CI Cancelled Gala', $at( '+4 days 19:00' ), $at( '+4 days 23:00' ), [ Meta::STATUS => 'cancelled', Meta::STATUS_REASON => 'rain' ] );

$res = gasf_ci_get( '/gasf-events/v1/events', [ 'fields' => 'id,title,short_title,sign_text,featured,priority,images,has_cover,status,status_reason' ] );
$ids = array_column( (array) $res->get_data(), 'id' );
gasf_ci_check( '/events returns 200 with the new events', 200 === $res->get_status() && in_array( $a, $ids, true ) && in_array( $b, $ids, true ), 'status ' . $res->get_status() );
$row = current( array_filter( (array) $res->get_data(), static fn( $e ) => $e['id'] === $a ) ) ?: [];
gasf_ci_check( 'promotion fields: featured, priority, sign_text, short_title falls back to title', true === ( $row['featured'] ?? null ) && 40 === ( $row['priority'] ?? null ) && 'Sat 7 pm' === ( $row['sign_text'] ?? null ) && 'CI Biergarten' === ( $row['short_title'] ?? null ), wp_json_encode( $row ) );
gasf_ci_check( 'images carries every size with dimensions; has_cover false', isset( $row['images']['full']['url'], $row['images']['medium']['width'] ) && false === ( $row['has_cover'] ?? null ) );

$f = gasf_ci_get( '/gasf-events/v1/events', [ 'status' => '-cancelled', 'fields' => 'id' ] );
gasf_ci_check( 'status=-cancelled leaves the cancelled event out', ! in_array( $b, array_column( (array) $f->get_data(), 'id' ), true ) && in_array( $a, array_column( (array) $f->get_data(), 'id' ), true ) );
$f = gasf_ci_get( '/gasf-events/v1/events', [ 'featured' => '1', 'fields' => 'id' ] );
gasf_ci_check( 'featured=1 returns only featured events', [ $a ] === array_values( array_intersect( array_column( (array) $f->get_data(), 'id' ), [ $a, $b ] ) ) );
gasf_ci_check( 'a misspelt field is a 400', 400 === gasf_ci_get( '/gasf-events/v1/events', [ 'fields' => 'titel' ] )->get_status() );
gasf_ci_check( 'an unknown status is a 400', 400 === gasf_ci_get( '/gasf-events/v1/events', [ 'status' => 'maybe' ] )->get_status() );

$one  = gasf_ci_get( "/gasf-events/v1/events/{$a}", [ 'fields' => 'id,title' ] );
$etag = (string) ( $one->get_headers()['ETag'] ?? '' );
gasf_ci_check( 'single event sends an ETag', '' !== $etag );
$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
gasf_ci_check( 'resending the ETag gets a 304', 304 === gasf_ci_get( "/gasf-events/v1/events/{$a}", [ 'fields' => 'id,title' ] )->get_status() );
unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

$since = gmdate( 'c', time() - 120 );
wp_update_post( [ 'ID' => $b, 'post_status' => 'draft' ] );
$ch = (array) gasf_ci_get( '/gasf-events/v1/events/changes', [ 'since' => $since ] )->get_data();
gasf_ci_check( '/events/changes lists the drafted event as removed', in_array( $b, (array) ( $ch['removed'] ?? [] ), true ) && array_key_exists( 'truncated', $ch ), wp_json_encode( array_keys( $ch ) ) );

/* ---- 3. Series ------------------------------------------------------ */
WP_CLI::log( 'Series' );
$first  = ( new DateTimeImmutable( '+7 days', $tz ) )->setTime( 19, 0 );
$master = gasf_ci_event( 'CI Euchre Night', $first->format( 'Y-m-d H:i:s' ), $first->modify( '+3 hours' )->format( 'Y-m-d H:i:s' ), [ Meta::REPEAT => 'weekly', Meta::REPEAT_COUNT => 4 ], 'draft' );
$sum    = Series::sync_from_master( $master, 'weekly', '', 4, false );
$sid    = (string) get_post_meta( $master, Meta::SERIES_ID, true );
$occ    = Series::members( $sid, 'occurrence' );
usort( $occ, static fn( $x, $y ) => (int) get_post_meta( $x, Meta::START_TS, true ) <=> (int) get_post_meta( $y, Meta::START_TS, true ) );
gasf_ci_check( 'a 4-date weekly series creates 3 occurrences', 3 === ( $sum['created'] ?? 0 ) && 3 === count( $occ ), wp_json_encode( $sum ) );
gasf_ci_check( 'every occurrence remembers its slot', 3 === count( array_filter( $occ, static fn( $o ) => '' !== (string) get_post_meta( $o, Meta::SERIES_SLOT, true ) ) ) );
gasf_ci_check( 'occurrences start as drafts like the master', [ 'draft' ] === array_values( array_unique( array_map( 'get_post_status', $occ ) ) ) );

wp_update_post( [ 'ID' => $master, 'post_status' => 'publish' ] );
gasf_ci_check( 'publishing the master publishes its future occurrences', [ 'publish' ] === array_values( array_unique( array_map( 'get_post_status', $occ ) ) ), wp_json_encode( array_map( 'get_post_status', $occ ) ) );

[ $o1, $o2, $o3 ] = $occ;
wp_update_post( [ 'ID' => $o2, 'post_title' => 'CI Euchre Night — Christmas special' ] );
gasf_ci_check( 'a hand edit marks the occurrence customized', Series::is_customized( $o2 ) );

wp_update_post( [ 'ID' => $master, 'post_title' => 'CI Euchre Night (new room)' ] );
Series::sync_from_master( $master, 'weekly', '', 4, true );
clean_post_cache( $o1 );
clean_post_cache( $o2 );
gasf_ci_check( '"apply to future" updates the untouched dates', 'CI Euchre Night (new room)' === get_post_field( 'post_title', $o1 ) );
gasf_ci_check( '"apply to future" leaves the hand-edited date alone', 'CI Euchre Night — Christmas special' === get_post_field( 'post_title', $o2 ) );

update_post_meta( $master, Meta::REPEAT_COUNT, 2 );
$plan = Series::current_plan( $master );
gasf_ci_check( 'shortening the rule: the untouched later date is an orphan', [ $o3 ] === $plan['orphans'], wp_json_encode( $plan ) );
gasf_ci_check( 'shortening the rule: the hand-edited date is kept, not offered for Trash', [ $o2 ] === $plan['kept_custom'] );

/* ---- 4. Output builders --------------------------------------------- */
WP_CLI::log( 'Output' );
$ev   = Event::get( $a );
$tags = array_column( \GASF_Events\Seo::tags( $ev ), 2, 1 );
gasf_ci_check( 'Open Graph: og:title and a description', 'CI Biergarten' === ( $tags['og:title'] ?? '' ) && '' !== ( $tags['description'] ?? '' ) );
$node = \GASF_Events\Schema::node( $ev );
gasf_ci_check( 'JSON-LD Event node with a startDate', 'Event' === ( $node['@type'] ?? '' ) && '' !== ( $node['startDate'] ?? '' ) );
$ics = \GASF_Events\Add_To_Calendar::ics_document( [ $ev ] );
gasf_ci_check( '.ics document has the event', str_contains( $ics, 'BEGIN:VEVENT' ) && str_contains( $ics, 'SUMMARY:CI Biergarten' ) );
$rows = \GASF_Events\Health::rows();
gasf_ci_check( 'Health screen builds its rows', count( $rows ) >= 8 );
gasf_ci_check( 'Health: front-end assets present', in_array( 'ok', array_column( array_filter( $rows, static fn( $r ) => 'Front-end assets' === $r[0] ), 1 ), true ) );
gasf_ci_check( 'Cache purge runs and reports', in_array( 'calendar.ics', \GASF_Events\Cache::purge(), true ) );

/* ---- 5. Webhooks ---------------------------------------------------- */
WP_CLI::log( 'Webhooks' );
update_option( \GASF_Events\Webhooks::OPTION, [ [ 'id' => 'wci', 'url' => 'https://example.invalid/hook', 'secret' => 's', 'enabled' => true, 'label' => 'CI', 'last_ts' => 0, 'last_status' => 0, 'last_error' => '' ] ], false );
wp_update_post( [ 'ID' => $a, 'post_title' => 'CI Biergarten (moved)' ] );
\GASF_Events\Webhooks::flush_pending();
gasf_ci_check( 'an undeliverable webhook is queued for retry and reported', \GASF_Events\Webhooks::queue_length() >= 1 && 1 === count( \GASF_Events\Webhooks::failing() ) );
delete_option( \GASF_Events\Webhooks::OPTION );
delete_option( \GASF_Events\Webhooks::QUEUE );

/* ---- 6. No PHP warnings from the plugin ----------------------------- */
WP_CLI::log( 'Log' );
$log  = WP_CONTENT_DIR . '/debug.log';
$bad  = [];
if ( is_readable( $log ) ) {
	foreach ( (array) file( $log ) as $line ) {
		if ( str_contains( $line, '/plugins/GASF-Events/' ) && preg_match( '/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $line ) ) {
			$bad[] = trim( $line );
		}
	}
}
gasf_ci_check( 'debug.log has no warnings from the plugin', ! $bad, implode( "\n      ", array_slice( $bad, 0, 10 ) ) );

$n = $GLOBALS['gasf_ci']['pass'];
$f = $GLOBALS['gasf_ci']['fail'];
if ( $f ) {
	WP_CLI::error( sprintf( '%d passed, %d failed: %s', $n, count( $f ), implode( '; ', $f ) ) );
}
WP_CLI::success( "{$n} integration checks passed." );
