<?php
/**
 * Google_Business_Profile — authenticated client for the Business Profile APIs.
 *
 * Structurally mirrors Google_Calendar (transient-cached token, bounded retry,
 * key file above docroot) but the AUTH MODEL IS DIFFERENT and does not carry
 * over: Calendar uses a service account (RS256 JWT); GBP locations are owned by
 * a human Google account and cannot be shared with a service account, so this
 * uses a refresh_token grant. See docs/GBP-HOURS-SYNC.md §3.
 *
 * TWO THINGS THAT WILL BREAK THIS IF CHANGED CARELESSLY:
 *
 * 1. Every call MUST send `x-goog-user-project: <QUOTA_PROJECT>`. The OAuth
 *    client lives in the personal `gasf-places` project, whose GBP quota is 0
 *    and always will be. The allowlisted project is a different one, and the
 *    project is baked into the credential — the client_id literally begins with
 *    its project number — so the header is the only way to run these
 *    credentials against the right quota. Without it every request is a 429.
 *
 * 2. The v1 "closed all day" flag is `closed`. The v4 docs call it `isClosed`.
 *    Using the v4 spelling produces periods that silently never close anything.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Google_Business_Profile {

	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const API_BASE    = 'https://mybusinessbusinessinformation.googleapis.com/v1';
	const ACCOUNTS    = 'https://mybusinessaccountmanagement.googleapis.com/v1';
	const TOKEN_CACHE = 'gasf_events_gbp_token';

	/**
	 * The allowlisted project the quota belongs to (gas-calendar-sync-500618).
	 * Not the project the OAuth client was created in — see the class note.
	 * Overridable so moving the client into that project later just drops the
	 * header rather than needing a code change.
	 */
	const QUOTA_PROJECT = '572555189848';

	/** Key file: { client_id, client_secret, refresh_token }, 600, above docroot. */
	public static function key_path(): string {
		return defined( 'GASF_GBP_KEY' ) ? GASF_GBP_KEY : ( dirname( ABSPATH ) . '/gasf-gbp-key.json' );
	}

	public static function available(): bool {
		return is_readable( self::key_path() );
	}

	private static function quota_project(): string {
		return defined( 'GASF_GBP_QUOTA_PROJECT' ) ? (string) GASF_GBP_QUOTA_PROJECT : self::QUOTA_PROJECT;
	}

	/* ---- Auth --------------------------------------------------------- */

	private static function token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}
		$key = json_decode( (string) @file_get_contents( self::key_path() ), true ); // phpcs:ignore
		if ( ! is_array( $key ) || empty( $key['client_id'] ) || empty( $key['client_secret'] ) || empty( $key['refresh_token'] ) ) {
			return new \WP_Error( 'gbp_key', 'GBP key file missing or not {client_id,client_secret,refresh_token}' );
		}
		$resp = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 30,
			'body'    => [
				'client_id'     => $key['client_id'],
				'client_secret' => $key['client_secret'],
				'refresh_token' => $key['refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			// A revoked refresh token lands here. The usual cause is the consent
			// screen slipping back to "Testing", which revokes after 7 days (§3).
			return new \WP_Error( 'gbp_token', 'token exchange failed: ' . ( $data['error_description'] ?? $data['error'] ?? 'unknown' ) );
		}
		set_transient( self::TOKEN_CACHE, $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return $data['access_token'];
	}

	/* ---- API ---------------------------------------------------------- */

	/** The accounts this login can see. Diagnostic only — see locations(). */
	public static function accounts() {
		return self::api( 'GET', self::ACCOUNTS . '/accounts', null );
	}

	/**
	 * Locations under an account.
	 *
	 * NOTE: accounts() listing a PERSONAL account with no obvious business is
	 * NOT evidence that the login manages no listing — account containers and
	 * locations are different things, and only this call answers the question.
	 * That misreading cost an afternoon once; see GBP-HOURS-SYNC.md §4.
	 */
	public static function locations( string $account, string $read_mask = 'name,title' ) {
		return self::api( 'GET', self::API_BASE . '/' . $account . '/locations?readMask=' . rawurlencode( $read_mask ) . '&pageSize=100', null );
	}

	/** @param string $location e.g. "locations/16878027369244959781" */
	public static function get_location( string $location, string $read_mask ) {
		return self::api( 'GET', self::API_BASE . '/' . $location . '?readMask=' . rawurlencode( $read_mask ), null );
	}

	/**
	 * PATCH a location. $update_mask names the top-level fields being replaced;
	 * anything named is REPLACED WHOLESALE, not merged — sending specialHours
	 * with two periods deletes every other period that was there.
	 */
	public static function patch_location( string $location, array $body, string $update_mask ) {
		return self::api( 'PATCH', self::API_BASE . '/' . $location . '?updateMask=' . rawurlencode( $update_mask ), $body );
	}

	/**
	 * One request, with bounded retry on the transient/rate-limited codes.
	 * Deliberately does NOT retry 401/403/404 — those are configuration, and
	 * retrying them just burns the 300/min quota three times as fast.
	 */
	private static function api( string $method, string $url, ?array $body ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization'      => 'Bearer ' . $token,
				'Content-Type'       => 'application/json',
				'x-goog-user-project' => self::quota_project(),
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$delay = 1;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$resp = wp_remote_request( $url, $args );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$raw  = wp_remote_retrieve_body( $resp );
			if ( $code < 400 ) {
				return json_decode( $raw, true ) ?: [];
			}
			if ( ! in_array( $code, [ 429, 500, 502, 503, 504 ], true ) ) {
				$err = json_decode( $raw, true );
				$msg = $err['error']['message'] ?? ( 'HTTP ' . $code );
				return new \WP_Error( 'gbp_api', 'HTTP ' . $code . ': ' . mb_strimwidth( (string) $msg, 0, 300, '…' ) );
			}
			if ( $attempt < 2 ) {
				usleep( (int) ( $delay * 1e6 ) );
				$delay = min( $delay * 2, 4 );
			}
		}
		return new \WP_Error( 'gbp_api', 'exhausted retries' );
	}
}
