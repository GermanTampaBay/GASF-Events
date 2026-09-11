<?php
/**
 * Event_Merge — collapses the SAME real-world event arriving from more than one
 * feed down to a single entry, so co-hosted events stop appearing two and three
 * times in the merged calendar.
 *
 * Only the Google Calendar destination runs this today: it is the only place
 * several feeds write to one calendar. The GASF Calendar dedups per-source UID
 * in Event_Ingest and currently takes exactly one feed, so it cannot collide.
 * The matcher is deliberately destination-agnostic so that stays true if the
 * ICS feeds are ever pointed at dest_gasf as well.
 *
 * WHY THE END TIME IS NOT OPTIONAL. Real overlap from the live feeds, all four
 * starting at the identical minute as the GASF entry:
 *
 *   GASF   Oktoberfest          12:00 → 22:00
 *   GCESV  Oktoberfest GASF     12:00 → 22:00   same event, merge
 *   GCESV  Oktoberfest Tampa    17:00 → 19:00   different org, do NOT merge
 *   GCESV  Wellen Park O'fest   12:00 → 14:00   different org, do NOT merge
 *
 * "Oktoberfest Tampa" contains "Oktoberfest" and starts on the same minute, so
 * a title-and-start match merges two unrelated festivals. The end time is the
 * only thing separating them, which is why a match requires start AND end AND
 * title, not any two of the three.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Event_Merge {

	/**
	 * How far apart two copies of one event may start and still be the same
	 * event. Calendars disagree by a few minutes about when a thing "starts"
	 * (doors vs. programme), but not by hours. Deliberately far tighter than the
	 * 3-hour gap that separates Oktoberfest Tampa from the GASF Oktoberfest.
	 */
	const START_TOLERANCE = 1800; // 30 minutes

	/** Same, for the end time. This is the discriminator — see the class note. */
	const END_TOLERANCE = 1800; // 30 minutes

	/**
	 * Similarity floor for titles that are not simply one inside the other,
	 * as a percentage from similar_text(). Catches "Oktoberfest 2026" vs
	 * "Oktoberfest 2O26" without reaching as far as two different festivals.
	 */
	const TITLE_SIMILARITY = 80;

	/**
	 * Collapse duplicates across feeds.
	 *
	 * $groups is an ordered list of [ 'feed' => $feed_config, 'events' => [] ].
	 * Order IS the priority: the earliest group wins any match, keeps its own
	 * title and times, and absorbs the loser's description. Callers put the
	 * definitive feed first — see Feeds::rank_for_merge().
	 *
	 * Returns the same structure with duplicates removed from the losing
	 * groups, plus a 'merges' log of what was collapsed into what, so a dry run
	 * can show it and a real run can record it. Nothing is silently dropped:
	 * every removal appears in that log.
	 *
	 * @param array $groups Ordered [ [ 'feed' => array, 'events' => array ], … ].
	 * @return array{groups:array,merges:array}
	 */
	public static function collapse( array $groups ): array {
		$merges = [];

		foreach ( $groups as $i => $group ) {
			// Compare every group against the ones ahead of it in priority order.
			foreach ( $group['events'] as $j => $candidate ) {
				$winner = self::find_winner( $groups, $i, $candidate );
				if ( null === $winner ) {
					continue;
				}
				[ $wi, $wj ] = $winner;

				$absorbed = self::absorb(
					(string) ( $groups[ $wi ]['events'][ $wj ]['description'] ?? '' ),
					(string) ( $candidate['description'] ?? '' ),
					(string) ( $group['feed']['label'] ?? 'feed' )
				);
				$groups[ $wi ]['events'][ $wj ]['description'] = $absorbed;

				$merges[] = [
					'kept'       => (string) ( $groups[ $wi ]['events'][ $wj ]['title'] ?? '' ),
					'kept_feed'  => (string) ( $groups[ $wi ]['feed']['label'] ?? '?' ),
					'dropped'    => (string) ( $candidate['title'] ?? '' ),
					'drop_feed'  => (string) ( $group['feed']['label'] ?? '?' ),
					'start'      => (string) ( $candidate['start'] ?? '' ),
				];

				unset( $groups[ $i ]['events'][ $j ] );
			}
			$groups[ $i ]['events'] = array_values( $groups[ $i ]['events'] );
		}

		return [ 'groups' => $groups, 'merges' => $merges ];
	}

	/**
	 * The highest-priority event $candidate duplicates, as [group, event] keys,
	 * or null when it is not a duplicate of anything ahead of it.
	 *
	 * Only groups BEFORE $before are searched, which is what makes the collapse
	 * deterministic: a duplicate always folds upward into the more definitive
	 * feed, never sideways, so the result does not depend on iteration order.
	 */
	private static function find_winner( array $groups, int $before, array $candidate ): ?array {
		foreach ( $groups as $gi => $group ) {
			if ( $gi >= $before ) {
				break;
			}
			foreach ( $group['events'] as $ei => $event ) {
				if ( self::same_event( $event, $candidate ) ) {
					return [ $gi, $ei ];
				}
			}
		}
		return null;
	}

	/** Two normalized feed events describing one real-world event? */
	public static function same_event( array $a, array $b ): bool {
		$a_start = self::ts( (string) ( $a['start'] ?? '' ) );
		$b_start = self::ts( (string) ( $b['start'] ?? '' ) );
		if ( ! $a_start || ! $b_start || abs( $a_start - $b_start ) > self::START_TOLERANCE ) {
			return false;
		}
		// An event with no end is treated as ending when it starts, so a missing
		// end never silently widens the match to anything sharing the start.
		$a_end = self::ts( (string) ( $a['end'] ?? '' ) ) ?: $a_start;
		$b_end = self::ts( (string) ( $b['end'] ?? '' ) ) ?: $b_start;
		if ( abs( $a_end - $b_end ) > self::END_TOLERANCE ) {
			return false;
		}
		return self::titles_match( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
	}

	/**
	 * Titles for the same event, allowing for one calendar tagging the host onto
	 * the name ("Oktoberfest" vs "Oktoberfest GASF").
	 */
	public static function titles_match( string $a, string $b ): bool {
		$x = self::normalize_title( $a );
		$y = self::normalize_title( $b );
		if ( '' === $x || '' === $y ) {
			return false;
		}
		if ( $x === $y ) {
			return true;
		}
		if ( str_contains( $x, $y ) || str_contains( $y, $x ) ) {
			return true;
		}
		similar_text( $x, $y, $pct );
		return $pct >= self::TITLE_SIMILARITY;
	}

	/**
	 * Lowercase, strip punctuation, collapse whitespace. Keeps letters and digits
	 * only, so "Wellen Park O'fest" and "Oktoberfest" cannot be brought together
	 * by the apostrophe, and "Oktoberfest — GASF" matches "Oktoberfest GASF".
	 */
	public static function normalize_title( string $t ): string {
		$t = wp_strip_all_tags( $t );
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
		$t = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t );
		return trim( (string) preg_replace( '/\s+/', ' ', $t ) );
	}

	/**
	 * Fold the dropped event's description into the one being kept.
	 *
	 * Always appends, under an attribution line naming the feed it came from —
	 * the merge is otherwise invisible, and an unattributed block of text
	 * appearing inside a GASF event description is impossible to account for
	 * later. An empty original simply takes the incoming text with no divider.
	 */
	public static function absorb( string $keep, string $incoming, string $from_label ): string {
		$incoming = trim( $incoming );
		if ( '' === $incoming ) {
			return $keep;
		}
		$keep = rtrim( $keep );
		if ( '' === $keep ) {
			return $incoming;
		}
		return $keep . "\n\n— " . $from_label . " —\n" . $incoming;
	}

	/** Parse a feed event's local "Y-m-d H:i:s" to a timestamp, 0 when unusable. */
	private static function ts( string $v ): int {
		$v = trim( $v );
		if ( '' === $v ) {
			return 0;
		}
		$t = strtotime( $v );
		return $t ?: 0;
	}
}
