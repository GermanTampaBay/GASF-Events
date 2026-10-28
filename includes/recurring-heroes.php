<?php
/**
 * Recurring Heroes — modules/23-recurring-heroes.php
 *
 * Event-name-driven, ephemeral home-page heroes. Instead of scheduling a hero
 * by hand for each occurrence of a repeating event (Euchre Night, Krampus
 * Verein Monthly Meetup, …), define it ONCE here. The resolver then makes it
 * the active hero automatically, starting `lead_days` (default 2) before the
 * next real matching event on the calendar and lasting until that event ends —
 * then it stops rendering. Nothing is forced onto the page:
 *
 *   - Driven off the calendar: it only appears if a matching `gasf_event`
 *     actually exists and is upcoming. Skip the event in a given month (no
 *     post) and no hero shows — no stale/"boring default" image.
 *   - Manual override: a hand-scheduled hero linked to a still-running event
 *     holds the slot until that event ends (the recurring hero then takes the
 *     rest of its window). Unlinked/standing heroes only outrank a recurring
 *     hero when their go-live is at/after the recurring trigger.
 *
 * Matching is by event TITLE — the GASF Events data has no formal series ids;
 * repeating events simply share a title (e.g. "Euchre Night"). A rule matches
 * either the exact title (the default) or, since v0.34.0, any title that
 * CONTAINS its text — the same case-insensitive substring rule Events →
 * Settings uses for the FC Bayern / Dinner Night lists — so one rule covers a
 * series whose events are all named differently ("FC Bayern v Dortmund",
 * "FC Bayern v Leipzig", …). With no image on the rule, each matched event's
 * own cover becomes the hero, so every game advertises itself.
 *
 * Moved 2026-07 from GASF-Utilities (modules/23-recurring-heroes.php) into
 * GASF-Events alongside heroes.php — event-name-driven, so it belongs with the
 * events it keys off. Public surface unchanged: the gasf_hero_active_entry
 * filter it hooks, the gasf_hero_recurring_* functions, the gasf_hero_recurring
 * option, and the gasf_hero_recurring_cron hook. Loaded AFTER heroes.php by the
 * plugin loader, so its shared helpers (gasf_hero_schedule_purge, the
 * gasf_events_help_box renderer) already exist.
 *
 * Gate: only runs when Heroes is active (Events → Settings). The
 * function_exists('gasf_hero_recurring_active') guard also makes a straddled
 * deploy (old Utilities copy still present) fatal-free.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'gasf_hero_recurring_active' ) && function_exists( 'gasf_hero_active' ) ) {

	if ( ! defined( 'GASF_HERO_RECURRING_OPTION' ) ) { define( 'GASF_HERO_RECURRING_OPTION', 'gasf_hero_recurring' ); }
	if ( ! defined( 'GASF_HERO_EVENT_CPT' ) ) { define( 'GASF_HERO_EVENT_CPT', defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event' ); }
	if ( ! defined( 'GASF_HERO_DEFAULT_LEAD_DAYS' ) ) { define( 'GASF_HERO_DEFAULT_LEAD_DAYS', 2 ); }

	/* ============================ data helpers ============================ */

	function gasf_hero_recurring_get() {
		$d = get_option( GASF_HERO_RECURRING_OPTION, array() );
		return is_array( $d ) ? $d : array();
	}
	function gasf_hero_recurring_save( $defs ) {
		update_option( GASF_HERO_RECURRING_OPTION, array_values( $defs ), false );
	}

	/**
	 * How a rule matches event titles: 'exact' (the default, and what every
	 * rule saved before v0.34.0 means — they have no 'match' key) or
	 * 'contains', a case-insensitive substring match.
	 */
	function gasf_hero_recurring_match_mode( $def ) {
		return ( is_array( $def ) && ( $def['match'] ?? '' ) === 'contains' ) ? 'contains' : 'exact';
	}

	/**
	 * The SQL operator and bound value for a rule's title condition, so both
	 * event queries share one definition of "matches". LIKE under MySQL's
	 * default collation is case-insensitive, which is what the Settings-page
	 * filters and the REST `contains=` parameter already rely on.
	 * Returns [ operator, value ] for "p.post_title {operator} %s".
	 */
	function gasf_hero_recurring_title_condition( $title, $match ) {
		global $wpdb;
		if ( $match === 'contains' ) {
			return array( 'LIKE', '%' . $wpdb->esc_like( $title ) . '%' );
		}
		return array( '=', $title );
	}

	/** Distinct titles of published events from ~a week ago onward (the pick list). */
	function gasf_hero_recurring_event_titles() {
		global $wpdb;
		$titles = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.post_title
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND CAST(m.meta_value AS UNSIGNED) >= %d
			  ORDER BY p.post_title ASC",
			GASF_HERO_EVENT_CPT,
			time() - 7 * DAY_IN_SECONDS
		) );
		return array_values( array_filter( array_map( 'trim', (array) $titles ) ) );
	}

	/**
	 * The soonest matching event whose active window contains `$now`.
	 * Active window = [ start - lead, end ].  $match is 'exact' or 'contains'
	 * (see gasf_hero_recurring_match_mode). Returns a row {id,title,start_ts,
	 * end_ts} or null. Picks the earliest-starting in-window occurrence (the
	 * most imminent one we've already crossed the lead threshold for).
	 */
	function gasf_hero_recurring_find_event( $title, $lead_secs, $now, $match = 'exact' ) {
		global $wpdb;
		list( $op, $val ) = gasf_hero_recurring_title_condition( $title, $match );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.ID AS id, p.post_title AS title,
			        CAST(ms.meta_value AS UNSIGNED) AS start_ts,
			        COALESCE(CAST(me.meta_value AS UNSIGNED), CAST(ms.meta_value AS UNSIGNED)) AS end_ts
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_gasf_start_ts'
			   LEFT JOIN {$wpdb->postmeta} me ON me.post_id = p.ID AND me.meta_key = '_gasf_end_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title {$op} %s
			    AND CAST(ms.meta_value AS UNSIGNED) <= %d
			    AND COALESCE(CAST(me.meta_value AS UNSIGNED), CAST(ms.meta_value AS UNSIGNED)) >= %d
			  ORDER BY CAST(ms.meta_value AS UNSIGNED) ASC
			  LIMIT 1",
			GASF_HERO_EVENT_CPT,
			$val,
			$now + $lead_secs, // start_ts <= now + lead  (lead window has opened)
			$now               // end_ts   >= now         (event not yet over)
		) );
		return $row ?: null;
	}

	/** The next upcoming occurrence (start in the future), for cron scheduling. */
	function gasf_hero_recurring_next_event( $title, $now, $match = 'exact' ) {
		global $wpdb;
		list( $op, $val ) = gasf_hero_recurring_title_condition( $title, $match );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT p.ID AS id, p.post_title AS title,
			        CAST(ms.meta_value AS UNSIGNED) AS start_ts,
			        COALESCE(CAST(me.meta_value AS UNSIGNED), CAST(ms.meta_value AS UNSIGNED)) AS end_ts
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_gasf_start_ts'
			   LEFT JOIN {$wpdb->postmeta} me ON me.post_id = p.ID AND me.meta_key = '_gasf_end_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_title {$op} %s
			    AND CAST(ms.meta_value AS UNSIGNED) >= %d
			  ORDER BY CAST(ms.meta_value AS UNSIGNED) ASC
			  LIMIT 1",
			GASF_HERO_EVENT_CPT,
			$val,
			$now
		) );
		return $row ?: null;
	}

	/** Build a virtual hero entry from a def + a matched event row. */
	function gasf_hero_recurring_entry( $def, $ev ) {
		$image_id = (int) ( $def['image_id'] ?? 0 );
		if ( ! $image_id ) { $image_id = (int) get_post_thumbnail_id( (int) $ev->id ); }
		if ( ! $image_id ) { return null; } // no image anywhere -> render nothing

		$image_url = isset( $def['image_url'] ) ? trim( $def['image_url'] ) : '';
		if ( $image_url === '' ) { $image_url = (string) get_permalink( (int) $ev->id ); }

		return array(
			'id'           => 'rec_' . ( $def['id'] ?? md5( $def['title'] ) ),
			'image_id'     => $image_id,
			'image_url'    => $image_url,
			'max_width'    => (int) ( $def['max_width'] ?? 450 ),
			'caption'      => (string) ( $def['caption'] ?? '' ),
			'button_label' => (string) ( $def['button_label'] ?? '' ),
			'button_url'   => (string) ( $def['button_url'] ?? '' ),
			'activate_at'  => (int) $ev->start_ts - (int) ( $def['_lead_secs'] ?? 0 ),
			'event_id'     => (int) $ev->id,
			'_recurring'   => true,
			'_expire_at'   => (int) $ev->end_ts,
			// Provenance for the Heroes schedule: the matched event's title (what
			// the page is actually advertising — under a "contains" rule every
			// occurrence is named differently), the rule's text and mode, and
			// its def id (so an Edit button can deep-link to that rule).
			'_title'       => (string) ( $ev->title ?? ( $def['title'] ?? '' ) ),
			'_rule'        => (string) ( $def['title'] ?? '' ),
			'_match'       => gasf_hero_recurring_match_mode( $def ),
			'_def_id'      => (string) ( $def['id'] ?? '' ),
		);
	}

	/** Best in-window recurring entry right now (greatest activation wins), or null. */
	function gasf_hero_recurring_active() {
		$now  = time();
		$best = null;
		foreach ( gasf_hero_recurring_get() as $def ) {
			if ( empty( $def['enabled'] ) || empty( $def['title'] ) ) { continue; }
			$lead = max( 0, (int) ( $def['lead_days'] ?? GASF_HERO_DEFAULT_LEAD_DAYS ) ) * DAY_IN_SECONDS;
			$ev   = gasf_hero_recurring_find_event( $def['title'], $lead, $now, gasf_hero_recurring_match_mode( $def ) );
			if ( ! $ev ) { continue; }
			$def['_lead_secs'] = $lead;
			$entry = gasf_hero_recurring_entry( $def, $ev );
			if ( ! $entry ) { continue; }
			if ( $best === null || $entry['activate_at'] > $best['activate_at'] ) { $best = $entry; }
		}
		return $best;
	}

	/**
	 * The single next recurring hero that will fire AFTER $anchor_ts — i.e. the
	 * soonest occurrence, across all enabled defs, whose activation (start - lead)
	 * is strictly later than the anchor. Used by the Heroes tab to show where the
	 * recurring schedule picks up beyond the furthest-out manual hero. Returns a
	 * virtual entry (with '_title') or null.
	 */
	function gasf_hero_recurring_next_after( $anchor_ts ) {
		$anchor_ts = (int) $anchor_ts;
		$best      = null;
		foreach ( gasf_hero_recurring_get() as $def ) {
			if ( empty( $def['enabled'] ) || empty( $def['title'] ) ) { continue; }
			$lead = max( 0, (int) ( $def['lead_days'] ?? GASF_HERO_DEFAULT_LEAD_DAYS ) ) * DAY_IN_SECONDS;
			// start_ts >= anchor + lead + 1  ->  activate_at (start - lead) > anchor.
			$ev = gasf_hero_recurring_next_event( $def['title'], $anchor_ts + $lead + 1, gasf_hero_recurring_match_mode( $def ) );
			if ( ! $ev ) { continue; }
			$def['_lead_secs'] = $lead;
			$entry = gasf_hero_recurring_entry( $def, $ev );
			if ( ! $entry ) { continue; }
			if ( $best === null || $entry['activate_at'] < $best['activate_at'] ) { $best = $entry; }
		}
		return $best;
	}

	/* ===================== front-end override (the hook) ================== */
	/* home-hero.php applies this filter to whatever gasf_hero_active() returned. */
	add_filter( 'gasf_hero_active_entry', function ( $manual ) {
		$r = gasf_hero_recurring_active();
		if ( ! $r ) { return $manual; }
		if ( is_array( $manual ) ) {
			// A manual hero tied to a still-running event HOLDS the slot until that
			// event ends — a recurring trigger opening mid-flight can't cut it off.
			// (gasf_hero_active already dropped expired manuals, so expires > now
			// here means "linked and not yet over".) When it ends, the recurring
			// hero takes whatever remains of its own window.
			$exp = function_exists( 'gasf_hero_entry_expires' ) ? gasf_hero_entry_expires( $manual ) : 0;
			if ( $exp > time() ) {
				return $manual;
			}
			// Unlinked/standing heroes have no end, so "newest activation wins"
			// still applies — otherwise an old standing hero would block every
			// recurring hero forever.
			if ( (int) ( $manual['activate_at'] ?? 0 ) >= (int) $r['activate_at'] ) {
				return $manual;
			}
		}
		return $r;
	}, 10 );

	/* ==================== cache-purge scheduling (cron) =================== */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_hero_recurring_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_hero_recurring_cron' );
		}
	} );
	add_action( 'gasf_hero_recurring_cron', 'gasf_hero_recurring_schedule_purges' );
	function gasf_hero_recurring_schedule_purges() {
		if ( ! function_exists( 'gasf_hero_schedule_purge' ) ) { return; } // provided by home-hero.php
		$now = time();
		foreach ( gasf_hero_recurring_get() as $def ) {
			if ( empty( $def['enabled'] ) || empty( $def['title'] ) ) { continue; }
			$lead = max( 0, (int) ( $def['lead_days'] ?? GASF_HERO_DEFAULT_LEAD_DAYS ) ) * DAY_IN_SECONDS;
			$ev   = gasf_hero_recurring_next_event( $def['title'], $now, gasf_hero_recurring_match_mode( $def ) );
			if ( ! $ev ) { continue; }
			$activate = (int) $ev->start_ts - $lead;
			$expire   = (int) $ev->end_ts;
			// Purge the home cache when the hero should appear and when it ends.
			if ( $activate > $now && $activate < $now + 8 * DAY_IN_SECONDS ) { gasf_hero_schedule_purge( $activate ); }
			if ( $expire > $now && $expire < $now + 8 * DAY_IN_SECONDS ) { gasf_hero_schedule_purge( $expire ); }
		}
	}

	/* ============================ admin screen =========================== */
	add_action( 'admin_menu', function () {
		$parent = 'edit.php?post_type=' . ( defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event' );
		$hook   = add_submenu_page( $parent, 'Recurring Heroes', 'Recurring Heroes', 'manage_options', 'gasf-events-recurring-heroes', 'gasf_hero_recurring_admin_page' );
		add_action( 'admin_enqueue_scripts', function ( $h ) use ( $hook ) {
			if ( $h === $hook ) { wp_enqueue_media(); }
		} );
	} );

	function gasf_hero_recurring_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		/* delete */
		if ( isset( $_POST['gasf_rhero_delete'] ) && check_admin_referer( 'gasf_rhero_action' ) ) {
			$del  = sanitize_text_field( wp_unslash( $_POST['gasf_rhero_delete'] ) );
			$defs = array_filter( gasf_hero_recurring_get(), function ( $d ) use ( $del ) { return ( $d['id'] ?? '' ) !== $del; } );
			gasf_hero_recurring_save( $defs );
			echo '<div class="notice notice-success is-dismissible"><p>Recurring hero deleted.</p></div>';
		}

		/* add / edit */
		if ( isset( $_POST['gasf_rhero_save'] ) && check_admin_referer( 'gasf_rhero_action' ) ) {
			$title = trim( sanitize_text_field( wp_unslash( $_POST['gasf_rhero_title'] ?? '' ) ) );
			if ( $title === '' && ! empty( $_POST['gasf_rhero_title_custom'] ) ) {
				$title = trim( sanitize_text_field( wp_unslash( $_POST['gasf_rhero_title_custom'] ) ) );
			}
			$image_id = (int) ( $_POST['gasf_rhero_image_id'] ?? 0 );
			if ( $title === '' ) {
				echo '<div class="notice notice-error"><p>Please choose (or type) an event name to match.</p></div>';
			} else {
				$data = array(
					'title'        => $title,
					'match'        => ( ( $_POST['gasf_rhero_match'] ?? '' ) === 'contains' ) ? 'contains' : 'exact',
					'image_id'     => $image_id,
					'image_url'    => esc_url_raw( wp_unslash( $_POST['gasf_rhero_image_url'] ?? '' ) ),
					'max_width'    => max( 0, (int) ( $_POST['gasf_rhero_max_width'] ?? 450 ) ),
					'caption'      => wp_kses_post( wp_unslash( $_POST['gasf_rhero_caption'] ?? '' ) ),
					'button_label' => sanitize_text_field( wp_unslash( $_POST['gasf_rhero_button_label'] ?? '' ) ),
					'button_url'   => esc_url_raw( wp_unslash( $_POST['gasf_rhero_button_url'] ?? '' ) ),
					'lead_days'    => max( 0, min( 60, (int) ( $_POST['gasf_rhero_lead_days'] ?? GASF_HERO_DEFAULT_LEAD_DAYS ) ) ),
					'enabled'      => ! empty( $_POST['gasf_rhero_enabled'] ) ? 1 : 0,
				);
				$edit_id = sanitize_text_field( wp_unslash( $_POST['gasf_rhero_edit_id'] ?? '' ) );
				$defs    = gasf_hero_recurring_get();
				$found   = false;
				if ( $edit_id !== '' ) {
					foreach ( $defs as &$d ) {
						if ( ( $d['id'] ?? '' ) === $edit_id ) { $d = array_merge( $d, $data ); $found = true; break; }
					}
					unset( $d );
				}
				if ( ! $found ) { $defs[] = array_merge( array( 'id' => uniqid( 'rh_', true ) ), $data ); }
				gasf_hero_recurring_save( $defs );
				if ( function_exists( 'gasf_hero_recurring_schedule_purges' ) ) { gasf_hero_recurring_schedule_purges(); }
				echo '<div class="notice notice-success is-dismissible"><p>Recurring hero saved.</p></div>';
			}
		}

		$defs   = gasf_hero_recurring_get();
		$titles = gasf_hero_recurring_event_titles();
		$now    = time();
		$tz     = wp_timezone_string();
		?>
		<h2>Recurring Heroes</h2>
		<?php
		if ( function_exists( 'gasf_events_help_box' ) ) {
			gasf_events_help_box( array(
				'what'   => 'Set-and-forget home-page heroes for repeating events (Euchre Night, Krampus Meetup, Biergarten, every FC Bayern game…). Define one entry per event name and the hero automatically goes live a few days before each occurrence on the events calendar and disappears when the event ends. If the event isn\'t on the calendar that month, nothing shows — the hero can never advertise something that isn\'t happening.',
				'needs'  => array(
					'The event on the GASF events calendar with the <em>same title</em> each time, or a recognisable piece of text in every title (that\'s how occurrences are matched — the calendar has no series IDs).',
					'The <code>[gas_hero]</code> shortcode on the home page (shared with the Heroes tab).',
				),
				'fields' => array(
					'Event name'                => 'Which calendar events trigger this hero. Pick from the dropdown (distinct upcoming titles) or type a title if it isn\'t listed yet. With the default <em>exact</em> match, "Euchre Night" won\'t match "Euchre Night Special".',
					'Match'                     => '<em>Exactly this title</em> for a repeating event that is always named the same. <em>Title contains</em> for a series whose events are all named differently — every FC Bayern game is its own event ("FC Bayern v Dortmund", "FC Bayern v Leipzig"), so one rule with the text <code>FC Bayern v</code> covers the whole season; case doesn\'t matter. It is the same rule as Events → Settings → Event list filters, so the text there works here.',
					'Image'                     => 'Optional. Blank = use the matched event\'s cover image, which keeps the hero fresh automatically. If the event has no cover either, no hero shows (by design — better nothing than a stale image).',
					'Image link (optional)'     => 'Where a click on the image goes. Blank = the matched event\'s own page, which is almost always what you want.',
					'Caption / Button'          => 'Same as the Heroes tab: caption text under the image, plus an optional call-to-action button with its own link.',
					'Show how many days before' => 'The lead time. The hero activates this many days before the event starts and retires when the event ends. 2–3 days is typical; longer for big festivals.',
					'Enabled'                   => 'Untick to pause this recurring hero without deleting its configuration.',
					'Advanced: display width'   => 'Max rendered width in px, centered (default 450). 0 = full content width.',
				),
				'notes'  => 'Precedence: a hand-scheduled hero (Heroes tab) that is linked to a still-running event holds the page until that event ends — then the recurring hero takes whatever remains of its lead window (e.g. manual ends Friday night, Saturday\'s recurring hero shows from Friday night). Unlinked standing heroes only beat a recurring hero if scheduled after its trigger.',
			) );
		}
		?>

		<h3 class="title">Add / edit a recurring hero</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_rhero_action' ); ?>
			<input type="hidden" id="gasf_rhero_edit_id" name="gasf_rhero_edit_id" value="">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gasf_rhero_title">Event name</label></th>
					<td>
						<select id="gasf_rhero_title" name="gasf_rhero_title" class="regular-text">
							<option value="">&mdash; choose an event &mdash;</option>
							<?php foreach ( $titles as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Not listed, or matching by text? <input type="text" name="gasf_rhero_title_custom" class="regular-text" placeholder="type an event title, or text to match" style="margin-top:4px"></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Match</th>
					<td>
						<label><input type="radio" name="gasf_rhero_match" value="exact" checked> Exactly this title</label><br>
						<label><input type="radio" name="gasf_rhero_match" value="contains"> Any event whose title <strong>contains</strong> this text</label>
						<p class="description">&ldquo;Contains&rdquo; is for a series whose events are all named differently, e.g. the text <code>FC Bayern v</code> matches every game of the season (keep the trailing &ldquo;v&rdquo; so an event that merely mentions the club doesn&rsquo;t match). Leave the image blank and each game&rsquo;s own cover becomes the hero.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Image</th>
					<td>
						<input type="hidden" id="gasf_rhero_image_id" name="gasf_rhero_image_id" value="">
						<div id="gasf_rhero_preview" style="margin-bottom:8px;max-width:460px"></div>
						<button type="button" class="button" id="gasf_rhero_pick">Choose image from Media Library</button>
						<p class="description">Optional. If left blank, the matched event&rsquo;s cover image is used; if that&rsquo;s also missing, no hero shows.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gasf_rhero_image_url">Image link (optional)</label></th>
					<td><input type="url" id="gasf_rhero_image_url" name="gasf_rhero_image_url" class="regular-text" placeholder="defaults to the event page">
					<p class="description">Whole image clickable. Defaults to the matched event&rsquo;s page.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="gasf_rhero_caption">Caption (optional)</label></th>
					<td><textarea id="gasf_rhero_caption" name="gasf_rhero_caption" rows="3" class="large-text" placeholder="Shown below the image. Basic HTML / links allowed."></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="gasf_rhero_button_label">Button label (optional)</label></th>
					<td><input type="text" id="gasf_rhero_button_label" name="gasf_rhero_button_label" class="regular-text" placeholder="e.g. Details"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gasf_rhero_button_url">Button link (optional)</label></th>
					<td><input type="url" id="gasf_rhero_button_url" name="gasf_rhero_button_url" class="regular-text" placeholder="https://…"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gasf_rhero_lead_days">Show how many days before</label></th>
					<td><input type="number" id="gasf_rhero_lead_days" name="gasf_rhero_lead_days" class="small-text" min="0" max="60" value="<?php echo (int) GASF_HERO_DEFAULT_LEAD_DAYS; ?>"> days
					<p class="description">Site time (<?php echo esc_html( $tz ); ?>). The hero goes live this many days before the event start and ends when the event ends.</p></td>
				</tr>
				<tr>
					<th scope="row">Enabled</th>
					<td><label><input type="checkbox" id="gasf_rhero_enabled" name="gasf_rhero_enabled" value="1" checked> Active</label></td>
				</tr>
			</table>
			<details id="gasf_rhero_adv" style="margin:6px 0">
				<summary style="cursor:pointer">Advanced: display width</summary>
				<p style="margin:8px 0 0"><label for="gasf_rhero_max_width">Display width</label> <input type="number" id="gasf_rhero_max_width" name="gasf_rhero_max_width" class="small-text" min="0" step="10" value="450"> px <span class="description">Max image width, centered. 0 = full width.</span></p>
			</details>
			<p>
				<button type="submit" id="gasf_rhero_submit" name="gasf_rhero_save" value="1" class="button button-primary">Save recurring hero</button>
				<button type="button" id="gasf_rhero_cancel" class="button" style="display:none;margin-left:8px">Cancel edit</button>
			</p>
		</form>

		<h3 class="title">Recurring heroes</h3>
		<table class="widefat striped">
			<thead><tr><th>Image</th><th>Event name</th><th>Lead</th><th>Next occurrence</th><th>Status</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $defs ) : ?>
				<tr><td colspan="6">No recurring heroes yet.</td></tr>
			<?php else : foreach ( $defs as $d ) :
				$lead   = max( 0, (int) ( $d['lead_days'] ?? GASF_HERO_DEFAULT_LEAD_DAYS ) );
				$match  = gasf_hero_recurring_match_mode( $d );
				$next   = ! empty( $d['title'] ) ? gasf_hero_recurring_next_event( $d['title'], $now, $match ) : null;
				$inwin  = ! empty( $d['enabled'] ) && ! empty( $d['title'] ) && gasf_hero_recurring_find_event( $d['title'], $lead * DAY_IN_SECONDS, $now, $match );
				$thumb  = $d['image_id'] ? wp_get_attachment_image( (int) $d['image_id'], array( 90, 90 ) ) : '<span style="color:#999">event cover</span>';
				$status = empty( $d['enabled'] )
					? '<span style="color:#888">disabled</span>'
					: ( $inwin ? '<strong style="color:#1a7f37">● LIVE NOW</strong>' : '<span style="color:#8250df">waiting</span>' );
			?>
				<tr>
					<td><?php echo $thumb; // phpcs:ignore ?></td>
					<td><strong><?php echo esc_html( $d['title'] ?? '' ); ?></strong><?php echo $match === 'contains' ? '<br><small style="color:#6d28d9">title contains</small>' : ''; ?></td>
					<td><?php echo (int) $lead; ?>d</td>
					<td><?php
						if ( $next ) {
							echo esc_html( wp_date( 'M j, Y g:i a', (int) $next->start_ts ) );
							// Under a "contains" rule the occurrence's own name is the useful part.
							if ( $match === 'contains' ) { echo '<br><small style="color:#666">' . esc_html( (string) $next->title ) . '</small>'; }
						} else {
							echo '<span style="color:#b3122b">none upcoming</span>';
						}
					?></td>
					<td><?php echo $status; // phpcs:ignore ?></td>
					<td style="white-space:nowrap">
						<button type="button" class="button gasf-rhero-edit"
							data-id="<?php echo esc_attr( $d['id'] ?? '' ); ?>"
							data-title="<?php echo esc_attr( $d['title'] ?? '' ); ?>"
							data-match="<?php echo esc_attr( $match ); ?>"
							data-image-id="<?php echo esc_attr( $d['image_id'] ?? '' ); ?>"
							data-image-url="<?php echo esc_attr( $d['image_url'] ?? '' ); ?>"
							data-max-width="<?php echo esc_attr( $d['max_width'] ?? '450' ); ?>"
							data-caption="<?php echo esc_attr( $d['caption'] ?? '' ); ?>"
							data-button-label="<?php echo esc_attr( $d['button_label'] ?? '' ); ?>"
							data-button-url="<?php echo esc_attr( $d['button_url'] ?? '' ); ?>"
							data-lead-days="<?php echo esc_attr( $lead ); ?>"
							data-enabled="<?php echo empty( $d['enabled'] ) ? '0' : '1'; ?>"
							data-thumb="<?php echo esc_attr( $d['image_id'] ? (string) wp_get_attachment_image_url( (int) $d['image_id'], 'large' ) : '' ); ?>"
							style="margin-bottom:4px">Edit</button>
						<form method="post" onsubmit="return confirm('Delete this recurring hero?');" style="margin:0">
							<?php wp_nonce_field( 'gasf_rhero_action' ); ?>
							<input type="hidden" name="gasf_rhero_delete" value="<?php echo esc_attr( $d['id'] ?? '' ); ?>">
							<button type="submit" class="button-link-delete">Delete</button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<script>
		jQuery(function($){
			$('#gasf_rhero_pick').on('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title:'Select hero image', multiple:false, library:{ type:'image' }, button:{ text:'Use this image' } });
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					$('#gasf_rhero_image_id').val(a.id);
					var url = (a.sizes && a.sizes.large) ? a.sizes.large.url : ((a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url);
					$('#gasf_rhero_preview').html('<img src="'+url+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">');
				});
				frame.open();
			});
			$(document).on('click', '.gasf-rhero-edit', function(){
				var b = $(this);
				$('#gasf_rhero_edit_id').val( b.attr('data-id') );
				// A "contains" rule's text (or an exact title no longer on the calendar)
				// isn't an option in the dropdown; .val() would silently blank it and
				// the save would then refuse. Fall through to the free-text box.
				var title = b.attr('data-title') || '';
				$('#gasf_rhero_title').val( title );
				$('input[name=gasf_rhero_title_custom]').val( $('#gasf_rhero_title').val() === title ? '' : title );
				$('input[name=gasf_rhero_match][value="' + ( b.attr('data-match') === 'contains' ? 'contains' : 'exact' ) + '"]').prop('checked', true);
				$('#gasf_rhero_image_id').val( b.attr('data-image-id') || '' );
				$('#gasf_rhero_image_url').val( b.attr('data-image-url') || '' );
				$('#gasf_rhero_max_width').val( b.attr('data-max-width') || '450' );
				$('#gasf_rhero_caption').val( b.attr('data-caption') );
				$('#gasf_rhero_button_label').val( b.attr('data-button-label') || '' );
				$('#gasf_rhero_button_url').val( b.attr('data-button-url') || '' );
				$('#gasf_rhero_lead_days').val( b.attr('data-lead-days') || '<?php echo (int) GASF_HERO_DEFAULT_LEAD_DAYS; ?>' );
				$('#gasf_rhero_enabled').prop('checked', b.attr('data-enabled') === '1');
				var thumb = b.attr('data-thumb');
				$('#gasf_rhero_preview').html( thumb ? '<img src="'+thumb+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">' : '' );
				$('#gasf_rhero_submit').text('Save changes');
				$('#gasf_rhero_cancel').show();
				$('html,body').animate({ scrollTop: $('#gasf_rhero_title').closest('table').offset().top - 80 }, 300);
			});
			$('#gasf_rhero_cancel').on('click', function(){
				$('#gasf_rhero_edit_id,#gasf_rhero_image_id,#gasf_rhero_image_url,#gasf_rhero_caption,#gasf_rhero_button_label,#gasf_rhero_button_url').val('');
				$('#gasf_rhero_title').val('');
				$('input[name=gasf_rhero_title_custom]').val('');
				$('input[name=gasf_rhero_match][value="exact"]').prop('checked', true);
				$('#gasf_rhero_max_width').val('450');
				$('#gasf_rhero_lead_days').val('<?php echo (int) GASF_HERO_DEFAULT_LEAD_DAYS; ?>');
				$('#gasf_rhero_enabled').prop('checked', true);
				$('#gasf_rhero_preview').html('');
				$('#gasf_rhero_submit').text('Save recurring hero');
				$(this).hide();
			});
			// Deep-link from the Heroes schedule: ?edit=<def id> opens that rule's editor.
			var rheroEditId = new URLSearchParams(window.location.search).get('edit');
			if ( rheroEditId ) {
				var $btn = $('.gasf-rhero-edit[data-id="' + rheroEditId.replace(/"/g,'') + '"]');
				if ( $btn.length ) { $btn.first().trigger('click'); }
			}
		});
		</script>
		<?php
	}
}
