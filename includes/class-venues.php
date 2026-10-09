<?php
/**
 * Venues — remembers every venue ever typed into an event's override fields, so
 * the next event at the same place is a dropdown pick instead of five fields
 * retyped from memory.
 *
 * Mostly for the subgroups: the club's own events are nearly all at the hall and
 * need no override at all, but Krampus Verein and the dancers perform around the
 * area and hit the same venues year after year.
 *
 * Stored as an option rather than a taxonomy on purpose. A venue here is five
 * loose strings with no identity of its own, nothing links to it, and nothing
 * queries by it — a taxonomy would add term rows, an admin UI and a migration to
 * model something that is really just a remembered form fill.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Venues {

	const OPTION = 'gasf_events_venues';

	/** The fields a remembered venue carries, in form order. */
	const FIELDS = [ 'name', 'street', 'city', 'state', 'zip' ];

	/**
	 * Every remembered venue, name-sorted.
	 *
	 * Back-fills from existing events the first time it is called, so venues
	 * typed before this feature existed are not lost. That scan runs once: the
	 * option is written even when nothing is found, so an empty result does not
	 * re-scan on every page load.
	 *
	 * @return array<string,array> keyed by fingerprint
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, null );
		if ( null === $saved ) {
			$saved = self::backfill();
		}
		$saved = is_array( $saved ) ? $saved : [];
		uasort( $saved, static fn( $a, $b ) => strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ) );
		return $saved;
	}

	/**
	 * Remember a venue. No-op for an empty name — the override is "optional,
	 * leave blank to use the club", and blank must not create a nameless entry.
	 *
	 * Re-saving a known venue overwrites it, so correcting a typo in the address
	 * on one event fixes the remembered copy rather than leaving two near
	 * duplicates in the list.
	 */
	public static function remember( array $venue ): void {
		$clean = self::clean( $venue );
		if ( '' === $clean['name'] ) {
			return;
		}
		$all = self::all();
		$all[ self::fingerprint( $clean ) ] = $clean;
		update_option( self::OPTION, $all, false );
	}

	public static function forget( string $fingerprint ): void {
		$all = self::all();
		unset( $all[ $fingerprint ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Identity is the name alone, case- and space-insensitive — not the whole
	 * address. "FloridaRAMA" typed with the suite number one time and without it
	 * the next is one venue the user is trying to reuse, not two.
	 */
	public static function fingerprint( array $venue ): string {
		$name = strtolower( trim( preg_replace( '/\s+/', ' ', (string) ( $venue['name'] ?? '' ) ) ) );
		return md5( $name );
	}

	/** Only the known fields, trimmed, as strings. */
	public static function clean( array $venue ): array {
		$out = [];
		foreach ( self::FIELDS as $f ) {
			$out[ $f ] = trim( (string) ( $venue[ $f ] ?? '' ) );
		}
		return $out;
	}

	/** One-time sweep of venue overrides already on events. */
	private static function backfill(): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
			Meta::VENUE_OVERRIDE
		) );
		$out = [];
		foreach ( (array) $rows as $raw ) {
			$v = maybe_unserialize( $raw );
			if ( ! is_array( $v ) ) {
				continue;
			}
			$clean = self::clean( $v );
			if ( '' !== $clean['name'] ) {
				$out[ self::fingerprint( $clean ) ] = $clean;
			}
		}
		update_option( self::OPTION, $out, false );
		return $out;
	}
}
