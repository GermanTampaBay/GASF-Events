<?php
/**
 * Event JSON-LD (schema.org/Event) for Google rich results.
 *
 * Single source of event structured data, emitted as a standalone <script>
 * block on single event pages. Always carries organizer (name+url), image
 * (cover → default), offers (when priced), and eventStatus.
 * See docs/ARCHITECTURE.md §5.7.
 *
 * NOTE: there is no SEO plugin on this site, so this block is the only thing
 * producing Event structured data. If one is ever installed, check it is not
 * also emitting an Event node for these pages — two would compete.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public function register_hooks(): void {
		add_action( 'wp_footer', [ $this, 'render' ] );
	}

	/** The Event JSON-LD for a single event page. */
	public function render(): void {
		if ( ! is_singular( GASF_EVENTS_CPT ) ) {
			return;
		}
		$event = Event::get( get_queried_object_id() );
		if ( ! $event ) {
			return;
		}
		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => [ self::node( $event ) ] ] ) .
			"</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Build the schema.org Event node. */
	public static function node( Event $event ): array {
		$start  = $event->start();
		$end    = $event->end();
		$status = $event->status();

		$node = [
			'@type'       => 'Event',
			'@id'         => $event->permalink() . '#event',
			'name'        => $event->title(),
			'description' => wp_strip_all_tags( $event->description() ),
			'url'         => $event->permalink(),
			'startDate'   => $event->start_iso(),
			'eventStatus' => self::status_url( $status ),
		];
		// Only emit a RASTER image — Google rejects SVG; omit rather than emit invalid.
		$img = $event->schema_image();
		if ( '' !== $img ) {
			$node['image'] = [ $img ];
		}
		if ( $end ) {
			$node['endDate'] = $event->end_iso();
		}

		// Attendance mode + location.
		if ( 'online_only' === $status ) {
			$node['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
			$node['location'] = [
				'@type' => 'VirtualLocation',
				'url'   => $event->online_link() ?: $event->permalink(),
			];
		} else {
			$node['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
			$v = $event->venue();
			$node['location'] = [
				'@type'   => 'Place',
				'name'    => $v['name'] ?? '',
				'address' => [
					'@type'           => 'PostalAddress',
					'streetAddress'   => $v['street'] ?? '',
					'addressLocality' => $v['city'] ?? '',
					'addressRegion'   => $v['state'] ?? '',
					'postalCode'      => $v['zip'] ?? '',
					'addressCountry'  => $v['country'] ?? 'US',
				],
			];
		}

		// Organizer — always name + URL.
		$o = $event->organizer();
		$node['organizer'] = [
			'@type' => 'Organization',
			'name'  => $o['name'] ?? '',
			'url'   => $o['url'] ?? home_url( '/' ),
		];

		// Offers — only when priced.
		$cost = $event->cost();
		if ( $cost > 0 ) {
			$node['offers'] = [
				'@type'         => 'Offer',
				'price'         => number_format( $cost, 2, '.', '' ),
				'priceCurrency' => 'USD',
				'availability'  => 'sold_out' === $status ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
				'url'           => $event->permalink(),
				'validFrom'     => get_post_time( 'c', true, $event->post() ),
			];
		}

		return $node;
	}

	private static function status_url( string $status ): string {
		switch ( $status ) {
			case 'cancelled':   return 'https://schema.org/EventCancelled';
			case 'postponed':   return 'https://schema.org/EventPostponed';
			case 'online_only': return 'https://schema.org/EventMovedOnline';
			default:            return 'https://schema.org/EventScheduled';
		}
	}
}
