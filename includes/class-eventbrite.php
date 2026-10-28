<?php
/**
 * Eventbrite — API v3 client + the first outbound Publish_Destination.
 * Publishes any GASF event (regardless of its source) to Eventbrite and keeps
 * the listing in sync. Gated by its own enable flag + a private token.
 *
 * v1 maps name + start/end (UTC) + a free ticket and publishes; v1.1 adds
 * the event's featured image as the Eventbrite logo on every push (create
 * and update) via Image_Uploader. Rich description (structured content)
 * and venue creation are follow-ons.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Eventbrite {

	const API     = 'https://www.eventbriteapi.com/v3';
	const OPTION  = 'gasf_events_eventbrite'; // { token, organization_id, enabled }

	public static function config(): array {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), [ 'token' => '', 'organization_id' => '', 'enabled' => false ] );
	}

	public static function enabled(): bool {
		$c = self::config();
		return ! empty( $c['enabled'] ) && '' !== $c['token'] && '' !== $c['organization_id'];
	}

	/**
	 * @return array|\WP_Error decoded body
	 */
	public static function api( string $method, string $path, ?array $body = null ) {
		$c = self::config();
		if ( '' === $c['token'] ) {
			return new \WP_Error( 'eb_token', 'no Eventbrite token' );
		}
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $c['token'], 'Content-Type' => 'application/json' ],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 ) {
			return new \WP_Error( 'eb_api', ( $data['error_description'] ?? ( 'HTTP ' . $code ) ) );
		}
		return is_array( $data ) ? $data : [];
	}
}

final class Eventbrite_Destination implements Publish_Destination {

	public function key(): string {
		return 'eventbrite';
	}

	public function label(): string {
		return __( 'Eventbrite', 'gasf-events' );
	}

	public function is_enabled(): bool {
		return Eventbrite::enabled();
	}

	public function push( Event $event, array $state ): array {
		$now  = time();
		$body = $this->build_body( $event );
		$id   = (string) ( $state['id'] ?? '' );

		// Image (best-effort; upload failure shouldn't block the rest of the push).
		$thumbnail_id = get_post_thumbnail_id( $event->id() );
		if ( $thumbnail_id ) {
			$cfg     = Eventbrite::config();
			$logo_id = ( new Image_Uploader( (string) $cfg['token'] ) )->upload_from_attachment( (int) $thumbnail_id );
			if ( $logo_id ) {
				$body['event']['logo_id'] = $logo_id;
			}
		}

		if ( $id ) {
			$res = Eventbrite::api( 'POST', "/events/{$id}/", $body );
			if ( is_wp_error( $res ) ) {
				return $this->err( $state, $res->get_error_message(), $now );
			}
			return [ 'id' => $id, 'url' => (string) ( $res['url'] ?? ( $state['url'] ?? '' ) ), 'status' => 'published', 'synced_at' => $now, 'error' => '' ];
		}

		$cfg = Eventbrite::config();
		$res = Eventbrite::api( 'POST', "/organizations/{$cfg['organization_id']}/events/", $body );
		if ( is_wp_error( $res ) || empty( $res['id'] ) ) {
			return $this->err( $state, is_wp_error( $res ) ? $res->get_error_message() : 'create failed', $now );
		}
		$new_id = (string) $res['id'];

		// A free ticket class is required before an event can be published.
		$ticket = Eventbrite::api( 'POST', "/events/{$new_id}/ticket_classes/", [
			'ticket_class' => [ 'name' => __( 'General Admission', 'gasf-events' ), 'free' => true, 'quantity_total' => 500 ],
		] );
		if ( is_wp_error( $ticket ) ) {
			return $this->err( [ 'id' => $new_id, 'url' => (string) ( $res['url'] ?? '' ) ], $ticket->get_error_message(), $now );
		}
		$pub = Eventbrite::api( 'POST', "/events/{$new_id}/publish/" );
		if ( is_wp_error( $pub ) ) {
			return $this->err( [ 'id' => $new_id, 'url' => (string) ( $res['url'] ?? '' ) ], $pub->get_error_message(), $now );
		}
		return [ 'id' => $new_id, 'url' => (string) ( $res['url'] ?? '' ), 'status' => 'published', 'synced_at' => $now, 'error' => '' ];
	}

	public function remove( array $state ): void {
		$id = (string) ( $state['id'] ?? '' );
		if ( $id ) {
			Eventbrite::api( 'POST', "/events/{$id}/unpublish/" );
		}
	}

	private function build_body( Event $event ): array {
		$tz        = wp_timezone_string();
		$start     = $event->start();
		$end       = $event->end();
		// Eventbrite requires end > start. Feed events often arrive with no end
		// (stored as end == start), and that used to be sent as-is, so every one
		// of them landed in the error column. Give it a default length instead.
		if ( $start && ( ! $end || $end <= $start ) ) {
			$end = $start->modify( '+2 hours' );
		}
		$utc       = static fn( ?\DateTimeImmutable $d ) => $d ? $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) : '';
		$summary   = mb_substr( trim( wp_strip_all_tags( $event->description() ) ), 0, 140 );

		return [
			'event' => [
				'name'         => [ 'html' => $event->title() ],
				'start'        => [ 'timezone' => $tz, 'utc' => $utc( $start ) ],
				'end'          => [ 'timezone' => $tz, 'utc' => $utc( $end ) ],
				'currency'     => 'USD',
				'summary'      => $summary,
				'online_event' => 'online_only' === $event->status(),
			],
		];
	}

	private function err( array $state, string $msg, int $now ): array {
		return array_merge( $state, [ 'status' => 'error', 'error' => $msg, 'synced_at' => $now ] );
	}
}
