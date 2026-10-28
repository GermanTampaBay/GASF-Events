<?php
/**
 * Facebook Graph API client — fetches a page's events. Read-only HTTP; no
 * WordPress writes here (the importer does those). Tokens are never logged.
 *
 * Replaces the third-party mec-advanced-importer's fetch layer. See §6.2.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class FB_Client {

	/**
	 * Pinned Graph API version. Meta retires each version about two years
	 * after release and serves calls to a retired one from the oldest version
	 * still live, so a stale pin keeps "working" while the fields underneath
	 * follow whatever version Meta chose. v19.0 (pinned through v0.33.x)
	 * expired 2026-05-21. v25.0 was released 2026-02-18; its own end date is
	 * on https://developers.facebook.com/docs/graph-api/changelog/versions/ —
	 * bump this before that date, not after the sync starts misbehaving.
	 */
	const API = 'https://graph.facebook.com/v25.0/';

	/**
	 * Fetch upcoming events for one account.
	 *
	 * @param array $account [ id, label, page_id, access_token, expire_at ]
	 * @return array{events:array,error:string}
	 */
	public static function fetch_events( array $account, int $past_grace_days = 2 ): array {
		$page  = trim( (string) ( $account['page_id'] ?? '' ) );
		$token = (string) ( $account['access_token'] ?? '' );
		if ( '' === $page || '' === $token ) {
			return [ 'events' => [], 'error' => 'missing page_id or token' ];
		}

		$fields = 'id,name,description,start_time,end_time,is_canceled,updated_time,place,cover{id,source},event_times';
		$url = add_query_arg( [
			'fields'       => $fields,
			'time_filter'  => 'upcoming',
			'since'        => time() - $past_grace_days * DAY_IN_SECONDS,
			'limit'        => 100,
			'access_token' => $token,
		], self::API . rawurlencode( $page ) . '/events' );

		$events = [];
		$guard  = 0;
		while ( $url && $guard++ < 10 ) {
			// SSRF guard: the first URL is built on our hardcoded Graph base, but the
			// paginated `next` URL is server-chosen — pin it to the Graph host and
			// block redirects to private/loopback ranges.
			if ( 'graph.facebook.com' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
				return [ 'events' => $events, 'error' => 'unexpected paging host' ];
			}
			$resp = wp_remote_get( $url, [ 'timeout' => 20, 'reject_unsafe_urls' => true ] );
			if ( is_wp_error( $resp ) ) {
				return [ 'events' => $events, 'error' => $resp->get_error_message() ];
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 200 !== $code ) {
				$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
				return [ 'events' => $events, 'error' => self::redact( $msg ) ];
			}
			foreach ( (array) ( $body['data'] ?? [] ) as $raw ) {
				$norm = self::normalize( $raw, (string) ( $account['id'] ?? '' ) );
				if ( $norm ) {
					$events[] = $norm;
				}
			}
			$url = $body['paging']['next'] ?? '';
		}

		return [ 'events' => $events, 'error' => '' ];
	}

	/**
	 * Normalize a raw FB event into our shape, expanding recurring `event_times`
	 * into one flat occurrence each (shared series id = base event id).
	 *
	 * @return array|null
	 */
	private static function normalize( array $raw, string $account_id ): ?array {
		$base_id = (string) ( $raw['id'] ?? '' );
		if ( '' === $base_id ) {
			return null;
		}
		$cover = $raw['cover'] ?? [];

		$common = [
			'name'        => (string) ( $raw['name'] ?? '' ),
			'description' => (string) ( $raw['description'] ?? '' ),
			'is_canceled' => ! empty( $raw['is_canceled'] ),
			'cover_id'    => (string) ( $cover['id'] ?? ( isset( $cover['source'] ) ? md5( $cover['source'] ) : '' ) ),
			'cover_url'   => (string) ( $cover['source'] ?? '' ),
			'updated'     => (string) ( $raw['updated_time'] ?? '' ),
			'account_id'  => $account_id,
		];

		$times = $raw['event_times'] ?? [];
		if ( is_array( $times ) && $times ) {
			// Recurring → one flat occurrence per entry, grouped as a series.
			$occ = [];
			foreach ( $times as $t ) {
				$occ[] = $common + [
					'fb_id'     => (string) ( $t['id'] ?? ( $base_id . '@' . ( $t['start_time'] ?? '' ) ) ),
					'series_id' => 'fb-' . $base_id,
					'is_series' => true,
					'start'     => self::dt( (string) ( $t['start_time'] ?? '' ) ),
					'end'       => self::dt( (string) ( $t['end_time'] ?? '' ) ),
				];
			}
			return [ 'recurring' => true, 'occurrences' => $occ ];
		}

		return [
			'recurring'   => false,
			'occurrences' => [ $common + [
				'fb_id'     => $base_id,
				'series_id' => '',
				'is_series' => false,
				'start'     => self::dt( (string) ( $raw['start_time'] ?? '' ) ),
				'end'       => self::dt( (string) ( $raw['end_time'] ?? '' ) ),
			] ],
		];
	}

	/** Parse an FB ISO datetime (with offset) into a site-local Y-m-d H:i:s string. */
	private static function dt( string $iso ): string {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return '';
		}
		try {
			$d = new \DateTimeImmutable( $iso );
			return $d->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/** Strip any access_token=... from an error string before it's stored/shown. */
	private static function redact( string $msg ): string {
		return preg_replace( '/access_token=[^&\s]+/i', 'access_token=REDACTED', $msg );
	}
}
