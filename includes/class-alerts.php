<?php
/**
 * Alerts — email notification when the sync auto-unpublishes events.
 *
 * Why: the 2-consecutive-misses auto-draft rule (Event_Ingest::prune_missing)
 * assumes "absent from the feed" means "removed at the source". The 2026-07-22
 * Feierabend incident proved Facebook's Graph API can silently drop a single
 * valid occurrence of a recurring event, hiding a live event from the site
 * with no visible trace. This class makes every auto-draft announce itself.
 *
 * One email per sync run (not per event, not per feed) — prune_missing()
 * notes each draft into a per-run collector; Feeds::run() flushes it once
 * at the end. Dry runs never note, so previews can't send mail.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	const OPT_EMAIL = 'gasf_events_alert_email';

	/** @var array[] Per-run collector: [ { id, title, start, feed_id, edit } ] */
	private static $drafted = [];

	public static function email(): string {
		return sanitize_email( (string) get_option( self::OPT_EMAIL, '' ) );
	}

	/**
	 * Send one alert to the configured address. The subject is prefixed with
	 * the site host so the inbox can tell sites apart. Returns false when no
	 * address is set or wp_mail() refused; never throws.
	 */
	public static function send( string $subject, string $body ): bool {
		$to = self::email();
		if ( '' === $to ) {
			return false;
		}
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return (bool) wp_mail( $to, '[' . $site . '] ' . $subject, $body );
	}

	/**
	 * Record one auto-drafted event. Called by Event_Ingest::prune_missing()
	 * at the moment it unpublishes; capture the details now, while they're
	 * cheap to read.
	 */
	public static function note_drafted( int $post_id, string $feed_id ): void {
		self::$drafted[] = [
			'id'      => $post_id,
			'title'   => (string) get_the_title( $post_id ),
			'start'   => (string) get_post_meta( $post_id, Meta::START, true ),
			'feed_id' => $feed_id,
			'edit'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		];
	}

	/**
	 * Send one summary email for everything noted this run, then reset.
	 * No-op when nothing was drafted or no alert address is configured.
	 */
	public static function flush(): void {
		$drafted = self::$drafted;
		self::$drafted = [];
		$to = self::email();
		if ( ! $drafted || '' === $to ) {
			return;
		}

		// Feed id → label, for readable provenance lines.
		$labels = [];
		foreach ( Feeds::feeds() as $f ) {
			$labels[ (string) ( $f['id'] ?? '' ) ] = (string) ( $f['label'] ?? '' );
		}

		$n     = count( $drafted );
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = [];
		foreach ( $drafted as $d ) {
			$feed    = ( $labels[ $d['feed_id'] ] ?? '' ) ?: $d['feed_id'];
			$lines[] = sprintf( "- %s\n  When: %s\n  Feed: %s\n  Edit: %s", $d['title'], $d['start'], $feed, $d['edit'] );
		}

		$subject = sprintf(
			/* translators: 1: site host, 2: number of events */
			__( '[%1$s] %2$d event(s) auto-unpublished — missing from source feed', 'gasf-events' ),
			$site,
			$n
		);
		$body = sprintf(
			/* translators: %d: number of events */
			__(
				"The events sync unpublished %d event(s) because the source feed stopped returning them for 2 consecutive runs:\n\n%s\n\nIf an event is genuinely gone from the source, nothing to do — this is the intended cleanup.\n\nIf it is STILL live at the source (e.g. still on the Facebook events page), the source API dropped a valid event. To restore it: open the Edit link, publish the event, and tick \"Sync locked\" so the sync leaves it alone from then on (locked events are never auto-unpublished, and no longer receive field updates from the feed).",
				'gasf-events'
			),
			$n,
			implode( "\n\n", $lines )
		);

		wp_mail( $to, $subject, $body );
	}
}
