<?php
/**
 * Plugin settings: the single venue, the organizer, and the default cover
 * image — the three globals that replace MEC's location/organizer taxonomies
 * and guarantee a schema `image`. See docs/ARCHITECTURE.md §4.3.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPT_VENUE      = 'gasf_events_venue';
	const OPT_ORGANIZER  = 'gasf_events_organizer';
	const OPT_DEFAULT_IMG = 'gasf_events_default_image';
	const OPT_HEROES     = 'gasf_events_heroes_enabled';
	const OPT_DINNER_FILTER = 'gasf_events_dinner_filter';
	const OPT_BAYERN_FILTER = 'gasf_events_bayern_filter';
	const OPT_TYPE_RULES = 'gasf_events_type_rules';

	/** Colour used when neither a rule nor the built-in map supplies one. */
	const DEFAULT_TYPE_COLOR = '#5a6b85';

	/**
	 * Admin-defined emoji + colour rules for the calendar chips, in priority
	 * order (first match wins). Each row is a case-insensitive CONTAINS match
	 * against the event TITLE only — deliberately narrower than the built-in
	 * keyword map in Event::builtin_type(), which also reads the description:
	 * a hand-written rule is an explicit decision about one kind of event, and
	 * matching stray description text would make it fire where nobody asked.
	 *
	 * A blank emoji or colour means "inherit" — that field keeps whatever the
	 * built-in map would have chosen, so a rule can restyle just the colour
	 * without also having to re-pick the emoji.
	 *
	 * Re-validated on read (not just on save) because the option is also
	 * reachable from WP-CLI and older saved shapes should never reach display.
	 *
	 * @return array<int, array{match:string, icon:string, color:string}>
	 */
	public static function type_rules(): array {
		$rows = get_option( self::OPT_TYPE_RULES, [] );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$match = trim( (string) ( $row['match'] ?? '' ) );
			if ( '' === $match ) {
				continue; // a row with nothing to match on is not a rule
			}
			$out[] = [
				'match' => $match,
				'icon'  => trim( (string) ( $row['icon'] ?? '' ) ),
				'color' => trim( (string) ( $row['color'] ?? '' ) ),
			];
		}
		return $out;
	}

	/**
	 * The title text that makes an event a "Dinner Night" for
	 * [gasf_dinner_events] (the list on /dinner-night/). Case-insensitive
	 * CONTAINS match against the event title; hardcoded to 'Dinner' through
	 * v0.17.x. A blank saved value falls back to 'Dinner' rather than to
	 * match-everything — an empty CONTAINS would put the entire calendar on
	 * the dinner page.
	 */
	public static function dinner_filter(): string {
		$v = trim( (string) get_option( self::OPT_DINNER_FILTER, '' ) );
		return '' !== $v ? $v : 'Dinner';
	}

	/**
	 * Same idea for [gasf_bayern_events]. The default 'FC Bayern v' is a
	 * deliberate prefix-of-a-phrase: as a CONTAINS match it catches
	 * "FC Bayern v X", "FC Bayern vs X" and cup naming like "DFB Pokalfinale
	 * FC Bayern v Stuttgart". Blank falls back to the default rather than to
	 * match-everything, same as the dinner filter.
	 */
	public static function bayern_filter(): string {
		$v = trim( (string) get_option( self::OPT_BAYERN_FILTER, '' ) );
		return '' !== $v ? $v : 'FC Bayern v';
	}

	/**
	 * Whether the home-page Heroes feature (heroes.php + recurring-heroes.php) runs.
	 * First read migrates from GASF-Utilities' legacy gate `gasf_mec_enable_hero`,
	 * so moving the feature between plugins changes nothing on a live site. Once
	 * saved from the Settings form, the Events option is authoritative.
	 */
	public static function heroes_enabled(): bool {
		$v = get_option( self::OPT_HEROES, null );
		if ( null === $v ) {
			$v = get_option( 'gasf_mec_enable_hero', '0' ); // inherit legacy Utilities gate
		}
		return (bool) (int) $v;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Turning Heroes off should stop its recurring cache-purge cron
		// (recurring-heroes.php only ever schedules it while enabled).
		add_action( 'update_option_' . self::OPT_HEROES, static function ( $old, $new ) {
			if ( ! (int) $new && wp_next_scheduled( 'gasf_hero_recurring_cron' ) ) {
				wp_clear_scheduled_hook( 'gasf_hero_recurring_cron' );
			}
		}, 10, 2 );
	}

	public static function venue(): array {
		return wp_parse_args( (array) get_option( self::OPT_VENUE, [] ), [
			'name'    => 'German-American Society',
			'street'  => '8098 66th Street North',
			'city'    => 'Pinellas Park',
			'state'   => 'FL',
			'zip'     => '33781',
			'country' => 'US',
			'lat'     => '',
			'lng'     => '',
			'hide_map' => false,
		] );
	}

	public static function organizer(): array {
		return wp_parse_args( (array) get_option( self::OPT_ORGANIZER, [] ), [
			'name' => 'German-American Society',
			'url'  => home_url( '/' ),
		] );
	}

	public static function default_image_id(): int {
		return (int) get_option( self::OPT_DEFAULT_IMG, 0 );
	}

	public static function default_image_url( string $size = 'large' ): string {
		$id = self::default_image_id();
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, $size );
			if ( $url ) {
				return $url;
			}
		}
		// Last-resort placeholder bundled with the plugin (admins should set a real
		// default image in Settings; this guarantees an image always exists).
		return GASF_EVENTS_URL . 'assets/img/event-default.svg';
	}

	public function add_settings_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . GASF_EVENTS_CPT,
			__( 'Event Settings', 'gasf-events' ),
			__( 'Settings', 'gasf-events' ),
			'manage_options',
			'gasf-events-settings',
			[ $this, 'render_settings_page' ]
		);
		// Media picker for the default image, only on this page. admin_enqueue_scripts
		// is the canonical hook — it fires early enough that wp.media loads reliably
		// (the old admin_print_scripts-{hook} could land media in the footer, after
		// the settings view's inline script had already run).
		add_action( 'admin_enqueue_scripts', static function ( $screen ) use ( $hook ) {
			if ( $screen === $hook ) {
				wp_enqueue_media();
			}
		} );
	}

	public function register_settings(): void {
		register_setting( 'gasf_events_settings', self::OPT_VENUE, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_venue' ],
		] );
		register_setting( 'gasf_events_settings', self::OPT_ORGANIZER, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_organizer' ],
		] );
		register_setting( 'gasf_events_settings', self::OPT_DEFAULT_IMG, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		] );
		register_setting( 'gasf_events_settings', Alerts::OPT_EMAIL, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
		] );
		register_setting( 'gasf_events_settings', self::OPT_HEROES, [
			'type'              => 'boolean',
			// Unchecked box submits nothing → store '0'; checked → '1'.
			'sanitize_callback' => static fn( $v ) => ! empty( $v ) ? '1' : '0',
		] );
		register_setting( 'gasf_events_settings', self::OPT_DINNER_FILTER, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'gasf_events_settings', self::OPT_BAYERN_FILTER, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		] );
		register_setting( 'gasf_events_settings', self::OPT_TYPE_RULES, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_type_rules' ],
		] );
	}

	/**
	 * Rows arrive as gasf_events_type_rules[N][match|icon|color]. Rows with a
	 * blank match are dropped, which is also how the UI deletes a rule: remove
	 * the row and it simply stops being submitted. Deleting every row submits
	 * nothing at all — options.php then passes null, which casts to an empty
	 * array here, so "no custom rules" saves correctly.
	 */
	public function sanitize_type_rules( $input ): array {
		$out = [];
		foreach ( (array) $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$match = sanitize_text_field( (string) ( $row['match'] ?? '' ) );
			if ( '' === trim( $match ) ) {
				continue;
			}
			$out[] = [
				'match' => trim( $match ),
				'icon'  => self::sanitize_icon( (string) ( $row['icon'] ?? '' ) ),
				'color' => self::sanitize_hex_color( (string) ( $row['color'] ?? '' ) ),
			];
		}
		return $out;
	}

	/**
	 * An emoji, or ''. sanitize_text_field() is UTF-8 safe (it strips tags and
	 * nulls, not multibyte characters), so the emoji survives; the length cap
	 * is what keeps somebody from pasting a paragraph into a 1-glyph column.
	 * 8 code points is generous enough for ZWJ sequences like 👨‍👩‍👧‍👦 and flags
	 * like 🇩🇪 (2 code points), which a tighter cap would truncate into mojibake.
	 */
	private static function sanitize_icon( string $v ): string {
		$v = trim( sanitize_text_field( $v ) );
		return '' === $v ? '' : mb_substr( $v, 0, 8 );
	}

	/**
	 * A #rgb / #rrggbb colour, or '' (meaning "inherit the built-in colour").
	 * Kept hand-rolled instead of core's sanitize_hex_color() (which does live
	 * in wp-includes/formatting.php and is always available) because this one
	 * also accepts a value typed without the leading '#', which the colour
	 * field's placeholder invites. An unparseable value returns '' rather than
	 * a fallback colour, so a typo inherits instead of silently painting grey.
	 */
	private static function sanitize_hex_color( string $v ): string {
		$v = trim( $v );
		if ( '' === $v ) {
			return '';
		}
		if ( '#' !== $v[0] ) {
			$v = '#' . $v;
		}
		return preg_match( '/^#([0-9a-f]{3}){1,2}$/i', $v ) ? strtolower( $v ) : '';
	}

	public function sanitize_venue( $input ): array {
		$input = (array) $input;
		return [
			'name'     => sanitize_text_field( $input['name'] ?? '' ),
			'street'   => sanitize_text_field( $input['street'] ?? '' ),
			'city'     => sanitize_text_field( $input['city'] ?? '' ),
			'state'    => sanitize_text_field( $input['state'] ?? '' ),
			'zip'      => sanitize_text_field( $input['zip'] ?? '' ),
			'country'  => sanitize_text_field( $input['country'] ?? 'US' ),
			'lat'      => sanitize_text_field( $input['lat'] ?? '' ),
			'lng'      => sanitize_text_field( $input['lng'] ?? '' ),
			'hide_map' => ! empty( $input['hide_map'] ),
		];
	}

	public function sanitize_organizer( $input ): array {
		$input = (array) $input;
		return [
			'name' => sanitize_text_field( $input['name'] ?? '' ),
			'url'  => esc_url_raw( $input['url'] ?? '' ),
		];
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$venue         = self::venue();
		$organizer     = self::organizer();
		$img_id        = self::default_image_id();
		$img_url       = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
		$alert_email   = Alerts::email();
		$dinner_filter = self::dinner_filter();
		$bayern_filter = self::bayern_filter();
		// One empty row so the table is never a bare header; it is dropped on
		// save (blank match), so an untouched form saves no rules.
		$type_rules = self::type_rules() ?: [ [ 'match' => '', 'icon' => '', 'color' => '' ] ];
		require GASF_EVENTS_DIR . 'admin/views/settings.php';
	}
}
