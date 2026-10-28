<?php
/**
 * Social and search metadata for single event pages.
 *
 * Events are reshared to the same Facebook they were imported from, and a
 * share card is built from Open Graph tags. Since the Yoast integration was
 * dropped (v0.33.1, there is no SEO plugin on this site) nothing emitted
 * og:image, og:description, a Twitter card or a meta description for an
 * event, so a share fell back to whatever the theme happens to print.
 * ARCHITECTURE §5.7 always said these come "from the theme"; they do not.
 *
 * Emits only on single event pages and only when no SEO plugin is active,
 * so installing one later does not produce two sets of tags. The canonical
 * link is left to core (rel_canonical runs on wp_head already).
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Seo {

	/** Meta description length Google tends to show in full. */
	const DESCRIPTION_MAX = 155;

	public function register_hooks(): void {
		// Early in wp_head so the tags sit near the top of <head>, where share
		// scrapers that stop reading after a few KB will still find them.
		add_action( 'wp_head', [ $this, 'render' ], 2 );
	}

	/** An SEO plugin that writes its own OG/Twitter tags is present. */
	public static function other_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )                 // Yoast
			|| class_exists( 'RankMath' )                 // Rank Math
			|| function_exists( 'aioseo' )                // All in One SEO
			|| defined( 'SEOPRESS_VERSION' )              // SEOPress
			|| class_exists( 'The_SEO_Framework\Load' );  // The SEO Framework
	}

	public function render(): void {
		if ( ! is_singular( GASF_EVENTS_CPT ) || self::other_seo_plugin_active() ) {
			return;
		}
		$event = Event::get( get_queried_object_id() );
		if ( ! $event ) {
			return;
		}
		echo "\n<!-- GASF Events: social and search metadata -->\n";
		foreach ( self::tags( $event ) as $tag ) {
			[ $attr, $name, $content ] = $tag;
			if ( '' === (string) $content ) {
				continue;
			}
			printf(
				'<meta %s="%s" content="%s">' . "\n",
				'property' === $attr ? 'property' : 'name',
				esc_attr( $name ),
				esc_attr( $content )
			);
		}
		echo "<!-- /GASF Events -->\n";
	}

	/**
	 * The tag list for an event: [ attr ('name'|'property'), key, content ].
	 * Filterable so a site can add or override without touching the plugin.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function tags( Event $event ): array {
		$title = $event->title();
		$desc  = self::description( $event );
		$url   = $event->permalink();
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$tags = [
			[ 'name',     'description',    $desc ],
			[ 'property', 'og:type',        'article' ],
			[ 'property', 'og:site_name',   $site ],
			[ 'property', 'og:locale',      (string) get_locale() ],
			[ 'property', 'og:url',         $url ],
			[ 'property', 'og:title',       $title ],
			[ 'property', 'og:description', $desc ],
		];

		// Raster only (schema_image() refuses the bundled SVG placeholder):
		// Facebook ignores SVG and a wrong og:image is worse than none.
		$img = $event->schema_image();
		if ( '' !== $img ) {
			$tags[] = [ 'property', 'og:image', $img ];
			$att_id = has_post_thumbnail( $event->post() ) ? (int) get_post_thumbnail_id( $event->post() ) : Settings::default_image_id();
			$src    = $att_id ? wp_get_attachment_image_src( $att_id, 'full' ) : false;
			if ( $src && ! empty( $src[1] ) && ! empty( $src[2] ) ) {
				$tags[] = [ 'property', 'og:image:width',  (string) (int) $src[1] ];
				$tags[] = [ 'property', 'og:image:height', (string) (int) $src[2] ];
			}
			$tags[] = [ 'property', 'og:image:alt', $title ];
			$tags[] = [ 'name', 'twitter:card',  'summary_large_image' ];
			$tags[] = [ 'name', 'twitter:image', $img ];
		} else {
			$tags[] = [ 'name', 'twitter:card', 'summary' ];
		}
		$tags[] = [ 'name', 'twitter:title',       $title ];
		$tags[] = [ 'name', 'twitter:description', $desc ];

		/**
		 * Add to or change the metadata emitted for an event page.
		 *
		 * @param array $tags  [ attr, key, content ] rows.
		 * @param Event $event The event.
		 */
		return (array) apply_filters( 'gasf_events_seo_tags', $tags, $event );
	}

	/**
	 * A share-card description: when and where, then the event text, cut to
	 * one line of search-result length. An event with no text still gets a
	 * useful "Saturday, November 7 at 7:00 pm · German-American Society".
	 */
	public static function description( Event $event ): string {
		$parts = [];
		$start = $event->start();
		if ( $start ) {
			$parts[] = ( $event->is_all_day() || $event->hide_time() )
				? wp_date( 'l, F j', $event->start_ts() )
				: wp_date( 'l, F j \a\t ' . get_option( 'time_format' ), $event->start_ts() );
		}
		$venue = (string) ( $event->venue()['name'] ?? '' );
		if ( '' !== $venue ) {
			$parts[] = $venue;
		}
		if ( '' !== $event->status() ) {
			$parts[] = $event->status_label();
		}
		$lead = implode( ' · ', $parts );

		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $event->description() ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$full = '' === $text ? $lead : ( '' === $lead ? $text : $lead . '. ' . $text );
		return mb_strimwidth( $full, 0, self::DESCRIPTION_MAX, '…' );
	}
}
