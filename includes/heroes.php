<?php
/**
 * Home Page Hero (scheduled) — includes/heroes.php
 *
 * Adds the [gas_hero] shortcode + a "Heroes" admin screen (Events → Heroes) that
 * lets a maintainer schedule the large image at the top of the home page. Each
 * entry is { image, optional image link, optional caption, optional button label
 * + button link, activation datetime }. Two independent links:
 *   - Image link  -> makes the whole image clickable.
 *   - Button link -> renders a button below the caption (can differ from the image link).
 *
 * The ACTIVE hero = the entry whose activation time is the latest one already
 * passed; future entries queue, and the current one stays up until the next
 * activates (never blank). When a future entry is saved, a one-off WP-Cron job
 * purges the home-page cache at activation time so the swap appears within ~a
 * minute despite the 24h nginx page cache.
 *
 * Moved 2026-07 from GASF-Utilities (modules/home-hero.php) into GASF-Events,
 * where it belongs: heroes advertise events and match them by end time / title.
 * The public contract is preserved verbatim so nothing downstream changes — the
 * [gas_hero] shortcode, the global gasf_hero_active() function, the
 * gasf_hero_active_entry filter, the gasf_hero_schedule_purge()/…_entry_expires()
 * helpers, the .gasf-hero* CSS, and every stored option (gasf_hero_entries,
 * gasf_hero_seeded, gasf_hero_lookahead_days). GASF-Utilities' 37-perf.php still
 * preloads the hero image via exactly that contract and needs no change.
 *
 * Gate: Events → Settings → "Home page heroes" (gasf_events_heroes_enabled;
 * first read inherits the legacy gasf_mec_enable_hero value → no behavior change).
 *
 * NOTE (procedural, not a class): a near-verbatim move of a large, proven admin
 * screen whose public surface is a set of GLOBAL functions that 37-perf.php and
 * the [gas_hero] shortcode bind to by name. Keeping it procedural preserves that
 * contract 1:1; the plugin loader require_once's it. The outer
 * function_exists('gasf_hero_active') guard makes a straddled deploy (old
 * Utilities module still present) fatal-free — Events defers to it until the
 * Utilities copy is removed.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'gasf_hero_active' ) && \GASF_Events\Settings::heroes_enabled() ) {

	if ( ! defined( 'GASF_HERO_OPTION' ) ) { define( 'GASF_HERO_OPTION', 'gasf_hero_entries' ); }

	/* ---------- data helpers ---------- */
	function gasf_hero_get_entries() {
		$e = get_option( GASF_HERO_OPTION, array() );
		return is_array( $e ) ? $e : array();
	}
	function gasf_hero_save_entries( $entries ) {
		// Prune heroes whose linked event ended > 30 days ago — the option grew
		// forever otherwise (every hero ever scheduled, iterated on each
		// [gas_hero] render). Standing heroes (no linked event → expires 0)
		// are never pruned; recent-ended ones are kept for the admin list.
		$cut = time() - 30 * DAY_IN_SECONDS;
		$entries = array_values( array_filter( $entries, function ( $e ) use ( $cut ) {
			$exp = gasf_hero_entry_expires( $e );
			return ! $exp || $exp > $cut;
		} ) );
		usort( $entries, function ( $a, $b ) { return ( (int) $a['activate_at'] ) <=> ( (int) $b['activate_at'] ); } );
		update_option( GASF_HERO_OPTION, $entries, false );
	}
	/**
	 * When an entry stops showing: the linked event's end, read live from the
	 * event meta (so a resynced event time is honored automatically).
	 * 0 = no linked event / no end recorded = never expires.
	 */
	function gasf_hero_entry_expires( $e ) {
		$ev = (int) ( $e['event_id'] ?? 0 );
		if ( ! $ev ) { return 0; }
		// Linked event deleted or trashed → retire the hero NOW. Without this,
		// the meta lookups below read '' on a gone post and returned 0 ("never
		// expires"), pinning a hero that advertises a 404'd event indefinitely
		// (and outranking recurring heroes via the activate_at comparison).
		$status = get_post_status( $ev );
		if ( false === $status || 'trash' === $status ) { return 1; }
		$end = (int) get_post_meta( $ev, '_gasf_end_ts', true );
		if ( ! $end ) { return 0; }
		// Some feed events record end == start (no real end). Retiring the hero
		// the second the event begins would be premature — give it 2h of runtime.
		$start = (int) get_post_meta( $ev, '_gasf_start_ts', true );
		return ( $end <= $start ) ? $start + 2 * HOUR_IN_SECONDS : $end;
	}

	/* ---------- per-event-name field memory (pre-fill for repeat events) ----------
	 * Saving a manual hero that's linked to an event remembers its optional
	 * fields (caption, button label, button URL) under the event's normalized
	 * name. The next time a similarly-named event appears in quick-create, the
	 * tile pre-fills those fields (Dinner Night, World Cup watch parties, …).
	 * The image link is deliberately NOT remembered — quick-create sets it to
	 * the new occurrence's permalink, and an old permalink would be stale. */

	/** Normalize an event title into a memory key: lowercase, subtitle after
	 *  ":" / "–" / "—" dropped ("World Cup Watch Party: USA vs Wales" and
	 *  "…: England vs France" share one memory), whitespace collapsed. */
	function gasf_hero_memory_key( $title ) {
		$t     = strtolower( trim( (string) $title ) );
		$parts = preg_split( '/\s*[:\x{2013}\x{2014}]\s*/u', $t );
		$t     = is_array( $parts ) && '' !== trim( (string) $parts[0] ) ? $parts[0] : $t;
		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}
	function gasf_hero_memory_all() {
		$m = get_option( 'gasf_hero_field_memory', array() );
		return is_array( $m ) ? $m : array();
	}
	/** Memory entry for an event title, or null. */
	function gasf_hero_memory_for( $title ) {
		$key = gasf_hero_memory_key( $title );
		if ( '' === $key ) { return null; }
		$m = gasf_hero_memory_all();
		return isset( $m[ $key ] ) ? $m[ $key ] : null;
	}
	/** Store/refresh the memory from a just-saved hero (last save wins). */
	function gasf_hero_memory_remember( $event_id, $data ) {
		$title = $event_id ? get_the_title( (int) $event_id ) : '';
		$key   = gasf_hero_memory_key( $title );
		if ( '' === $key ) { return; }
		$fields = array(
			'caption'      => (string) ( $data['caption'] ?? '' ),
			'button_label' => (string) ( $data['button_label'] ?? '' ),
			'button_url'   => (string) ( $data['button_url'] ?? '' ),
		);
		if ( '' === trim( $fields['caption'] . $fields['button_label'] . $fields['button_url'] ) ) { return; }
		$m         = gasf_hero_memory_all();
		$m[ $key ] = $fields + array( 'title' => $title, 'ts' => time() );
		if ( count( $m ) > 40 ) { // cap: keep the 40 most recently saved
			uasort( $m, function ( $a, $b ) { return ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ); } );
			$m = array_slice( $m, 0, 40, true );
		}
		update_option( 'gasf_hero_field_memory', $m, false );
	}

	/* Active = latest entry whose activation has passed AND whose linked event
	 * (if any) hasn't ended. Ended heroes fall away, so the next-newest
	 * still-valid hero (or a recurring one) shows instead. */
	function gasf_hero_active() {
		$now = time();
		$active = null;
		foreach ( gasf_hero_get_entries() as $e ) {
			$ts = (int) $e['activate_at'];
			if ( $ts > $now ) { continue; }
			$exp = gasf_hero_entry_expires( $e );
			if ( $exp > 0 && $exp <= $now ) { continue; } // event over — hero retired
			if ( $active === null || $ts >= (int) $active['activate_at'] ) {
				$active = $e;
			}
		}
		return $active;
	}

	/* ---------- one-time seed: keep the current image (#18254) live so cutover is seamless ---------- */
	add_action( 'init', function () {
		if ( get_option( 'gasf_hero_seeded' ) ) { return; }
		if ( ! gasf_hero_get_entries() ) {
			gasf_hero_save_entries( array( array(
				'id'           => 'seed_18254',
				'image_id'     => 18254,
				'image_url'    => 'https://germantampabay.com/world-cup/',
				'max_width'    => 450,
				'caption'      => '',
				'button_label' => '',
				'button_url'   => '',
				'activate_at'  => time() - 3600, // already active
				'created'      => time(),
			) ) );
		}
		update_option( 'gasf_hero_seeded', '1' );
	} );

	/* ---------- front-end shortcode ---------- */
	add_shortcode( 'gas_hero', 'gasf_hero_shortcode' );
	function gasf_hero_shortcode() {
		$e = gasf_hero_active();
		// Recurring-hero resolver (modules/23-recurring-heroes.php) may override
		// the standing/manual hero during a repeating event's window, or supply
		// one when there is no manual hero at all.
		$e = apply_filters( 'gasf_hero_active_entry', $e );
		if ( ! $e ) { return ''; }
		$img = wp_get_attachment_image( (int) $e['image_id'], 'full', false, array(
			'class' => 'gasf-hero__img',
			'alt'   => $e['caption'] !== '' ? esc_attr( wp_strip_all_tags( $e['caption'] ) ) : get_bloginfo( 'name' ),
		) );
		if ( ! $img ) { return ''; }

		// Hero is the LCP element: its eager/high-priority loading + right-sized
		// `sizes` + <head> preload are owned by modules/37-perf.php (A1/A2).

		// Whole image clickable when an image link is set (independent of the button link).
		$image_url = isset( $e['image_url'] ) ? trim( $e['image_url'] ) : '';
		if ( $image_url !== '' ) {
			$img = '<a class="gasf-hero__imglink" href="' . esc_url( $image_url ) . '">' . $img . '</a>';
		}

		$has_caption = trim( $e['caption'] ) !== '';
		$has_button  = isset( $e['button_url'] ) && trim( $e['button_url'] ) !== '';

		$mw        = isset( $e['max_width'] ) ? (int) $e['max_width'] : 0;
		$fig_style = $mw > 0 ? ' style="max-width:' . $mw . 'px;margin-left:auto;margin-right:auto"' : '';
		$out  = gasf_hero_css();
		$out .= '<figure class="gasf-hero"' . $fig_style . '>' . $img;
		if ( $has_caption || $has_button ) {
			$out .= '<figcaption class="gasf-hero__cap">';
			if ( $has_caption ) {
				$out .= '<div class="gasf-hero__text">' . wp_kses_post( wpautop( $e['caption'] ) ) . '</div>';
			}
			if ( $has_button ) {
				$label = trim( $e['button_label'] ) !== '' ? $e['button_label'] : 'Learn More';
				$out  .= '<a class="gasf-hero__btn" href="' . esc_url( $e['button_url'] ) . '">' . esc_html( $label ) . '</a>';
			}
			$out .= '</figcaption>';
		}
		$out .= '</figure>';
		return $out;
	}

	function gasf_hero_css() {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		return '<style>'
			. '.gasf-hero{margin:0;width:100%}'
			. '.gasf-hero__imglink{display:block}'
			. '.gasf-hero__img{display:block;width:100%;height:auto;box-sizing:border-box;'
			. 'border:6px solid transparent;'
			. 'border-image:linear-gradient(45deg,var(--of-white) 25%,var(--of-teal) 25%,var(--of-teal) 50%,var(--of-white) 50%,var(--of-white) 75%,var(--of-teal) 75%) 1;'
			. 'border-image-slice:1;'
			. 'transition:transform .2s ease,box-shadow .2s ease}'
			. '.gasf-hero__img:hover{transform:scale(1.05);box-shadow:0 8px 20px rgba(0,0,0,0.35)}'
			. '.gasf-hero__cap{text-align:center;padding:14px 16px}'
			. '.gasf-hero__text{font-size:1.1rem;line-height:1.45;max-width:760px;margin:0 auto 12px}'
			. '.gasf-hero__btn{display:inline-block;padding:10px 24px;border-radius:6px;background:#a4161a;color:#fff;text-decoration:none;font-weight:600}'
			. '.gasf-hero__btn:hover{filter:brightness(1.08)}'
			. '</style>';
	}

	/* ---------- cache purge at activation time ---------- */
	add_action( 'gasf_hero_activate_event', 'gasf_hero_purge_home' );
	function gasf_hero_purge_home() {
		// gasf_mec_log() is the GASF-Utilities logger — present on these sites but
		// not a hard dependency of this plugin, so it stays function_exists-guarded.
		if ( function_exists( 'gasf_mec_log' ) ) { gasf_mec_log( 'HERO activation -> purging home-page cache' ); }
		do_action( 'epc_purge' );
		$home = (int) get_option( 'page_on_front' );
		if ( $home ) {
			clean_post_cache( $home );
			wp_update_post( array(
				'ID'                => $home,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			) );
		}
		do_action( 'epc_purge' );
	}
	function gasf_hero_schedule_purge( $ts ) {
		$ts = (int) $ts;
		if ( $ts > time() ) {
			wp_schedule_single_event( $ts, 'gasf_hero_activate_event' );
		}
	}

	/* ---------- upcoming GASF Events (next N days) for quick-create ---------- */
	/* Repointed from retired Modern Events Calendar to the native gasf_event
	 * calendar, which stores UTC unix timestamps in _gasf_start_ts / _gasf_end_ts. */
	function gasf_hero_upcoming_events( $days = 7 ) {
		$cpt   = defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event';
		$now   = time();
		$until = $now + max( 1, (int) $days ) * DAY_IN_SECONDS;
		$q = new WP_Query( array(
			'post_type'      => $cpt,
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'meta_key'       => '_gasf_start_ts',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => '_gasf_start_ts',
				'value'   => array( $now, $until ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			) ),
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$start_ts = (int) get_post_meta( $p->ID, '_gasf_start_ts', true );
			if ( ! $start_ts ) { continue; }
			$tid   = (int) get_post_thumbnail_id( $p->ID );
			$mem   = gasf_hero_memory_for( get_the_title( $p->ID ) );
			$out[] = array(
				'id'               => $p->ID,
				'title'            => get_the_title( $p->ID ),
				'image_id'         => $tid,
				'thumb'            => $tid ? wp_get_attachment_image_url( $tid, 'medium' ) : '',
				'url'              => get_permalink( $p->ID ),
				'activate'         => wp_date( 'Y-m-d\TH:i', $start_ts - 72 * HOUR_IN_SECONDS ),
				'when'             => wp_date( 'D M j · g:i a', $start_ts ),
				'mem_caption'      => (string) ( $mem['caption'] ?? '' ),
				'mem_button_label' => (string) ( $mem['button_label'] ?? '' ),
				'mem_button_url'   => (string) ( $mem['button_url'] ?? '' ),
				'has_mem'          => (bool) $mem,
			);
		}
		wp_reset_postdata();
		return $out;
	}

	/* ---------- event end-time label for the scheduled-heroes table ---------- */
	function gasf_hero_event_end_label( $event_id ) {
		$dash = '<span style="color:#999">&mdash;</span>';
		if ( ! $event_id ) { return $dash; }
		$cpt = defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event';
		$p = get_post( $event_id );
		if ( ! $p || $p->post_type !== $cpt ) { return $dash; }
		$start_ts = (int) get_post_meta( $event_id, '_gasf_start_ts', true );
		$end_ts   = (int) get_post_meta( $event_id, '_gasf_end_ts', true );
		if ( ! $start_ts ) { return $dash; }
		if ( $end_ts > $start_ts ) {
			return esc_html( wp_date( 'M j, Y g:i a', $end_ts ) );
		}
		// no distinct end time -> show the start time with an asterisk
		return esc_html( wp_date( 'M j, Y g:i a', $start_ts ) )
			. ' <abbr title="No recorded end time" style="text-decoration:none;color:#b3122b;font-weight:700">*</abbr>';
	}

	/**
	 * Render one recurring-hero row for the "Scheduled heroes" table. Shared by
	 * the LIVE-NOW recurring row and the next-recurring projection so both look
	 * the same and both get an Edit button that deep-links to the specific rule
	 * on the Recurring Heroes page (?edit=<def id>).
	 */
	function gasf_hero_recurring_row_html( $rec, $status_html ) {
		$thumb  = wp_get_attachment_image( (int) $rec['image_id'], array( 90, 90 ) );
		$def_id = (string) ( $rec['_def_id'] ?? '' );
		$page   = 'edit.php?post_type=' . ( defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event' ) . '&page=gasf-events-recurring-heroes';
		$edit   = admin_url( $page . ( '' !== $def_id ? '&edit=' . rawurlencode( $def_id ) : '' ) );
		ob_start();
		?>
		<tr style="background:#f6f3fb">
			<td><?php echo $thumb ? $thumb : '#' . (int) $rec['image_id']; // phpcs:ignore ?></td>
			<td><?php echo esc_html( wp_date( 'M j, Y g:i a', (int) $rec['activate_at'] ) ); ?></td>
			<td><?php echo gasf_hero_event_end_label( isset( $rec['event_id'] ) ? (int) $rec['event_id'] : 0 ); // phpcs:ignore ?></td>
			<td><?php echo $status_html; // phpcs:ignore ?></td>
			<td>
				<strong><?php echo esc_html( $rec['_title'] ?? '' ); ?></strong><br>
				<?php echo ( isset( $rec['caption'] ) && '' !== $rec['caption'] ) ? esc_html( wp_trim_words( wp_strip_all_tags( $rec['caption'] ), 12 ) ) . '<br>' : ''; // phpcs:ignore ?>
				<small style="color:#666">Auto-scheduled from the Recurring Heroes rule<?php
					$rule = (string) ( $rec['_rule'] ?? '' );
					if ( '' !== $rule && $rule !== (string) ( $rec['_title'] ?? '' ) ) {
						echo ' &ldquo;' . esc_html( $rule ) . '&rdquo;' . ( ( $rec['_match'] ?? '' ) === 'contains' ? ' (title contains)' : '' );
					}
				?>.</small>
			</td>
			<td style="white-space:nowrap"><a class="button" href="<?php echo esc_url( $edit ); ?>">Edit</a></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/* ---------- admin screen ---------- */
	add_action( 'admin_menu', function () {
		$parent = 'edit.php?post_type=' . ( defined( 'GASF_EVENTS_CPT' ) ? GASF_EVENTS_CPT : 'gasf_event' );
		$hook   = add_submenu_page( $parent, 'Home Page Hero', 'Heroes', 'manage_options', 'gasf-events-heroes', 'gasf_hero_admin_page' );
		add_action( 'admin_enqueue_scripts', function ( $h ) use ( $hook ) {
			if ( $h === $hook ) { wp_enqueue_media(); }
		} );
	} );

	/**
	 * Lightweight "About this utility" help box — the Events-native replacement
	 * for GASF-Utilities' gasf_utilities_doc_panel(). Same array shape
	 * (what / needs[] / fields{} / notes) so the two moved screens keep their
	 * guidance verbatim. Defined once here; recurring-heroes.php reuses it.
	 */
	if ( ! function_exists( 'gasf_events_help_box' ) ) {
		function gasf_events_help_box( $a ) {
			echo '<details class="gasf-help" style="margin:10px 0 18px;border:1px solid #dcdcde;border-radius:6px;background:#fff;max-width:900px">';
			echo '<summary style="cursor:pointer;padding:10px 14px;font-weight:600">&#128214; About this screen</summary>';
			echo '<div style="padding:4px 16px 14px">';
			if ( ! empty( $a['what'] ) ) { echo '<p>' . wp_kses_post( $a['what'] ) . '</p>'; }
			if ( ! empty( $a['needs'] ) ) {
				echo '<p style="margin-bottom:4px"><strong>Needs</strong></p><ul style="list-style:disc;margin:0 0 10px 22px">';
				foreach ( (array) $a['needs'] as $n ) { echo '<li>' . wp_kses_post( $n ) . '</li>'; }
				echo '</ul>';
			}
			if ( ! empty( $a['fields'] ) ) {
				echo '<table class="widefat striped" style="margin:6px 0"><tbody>';
				foreach ( (array) $a['fields'] as $label => $desc ) {
					echo '<tr><th style="width:220px">' . wp_kses_post( $label ) . '</th><td>' . wp_kses_post( $desc ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( ! empty( $a['notes'] ) ) { echo '<p style="color:#50575e">' . wp_kses_post( $a['notes'] ) . '</p>'; }
			echo '</div></details>';
		}
	}

	function gasf_hero_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		/* delete */
		if ( isset( $_POST['gasf_hero_delete'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$del     = sanitize_text_field( wp_unslash( $_POST['gasf_hero_delete'] ) );
			$entries = array_values( array_filter( gasf_hero_get_entries(), function ( $e ) use ( $del ) { return $e['id'] !== $del; } ) );
			gasf_hero_save_entries( $entries );
			echo '<div class="notice notice-success is-dismissible"><p>Hero entry deleted.</p></div>';
		}

		/* set the quick-create look-ahead window (days) */
		if ( isset( $_POST['gasf_hero_set_days'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$d = max( 1, min( 365, (int) ( $_POST['gasf_hero_days'] ?? 14 ) ) );
			update_option( 'gasf_hero_lookahead_days', $d, false );
			echo '<div class="notice notice-success is-dismissible"><p>Quick-create list now shows the next ' . esc_html( $d ) . ' days.</p></div>';
		}

		/* forget one remembered-fields entry */
		if ( isset( $_POST['gasf_hero_forget'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$k = sanitize_text_field( wp_unslash( $_POST['gasf_hero_forget'] ) );
			$m = gasf_hero_memory_all();
			unset( $m[ $k ] );
			update_option( 'gasf_hero_field_memory', $m, false );
			echo '<div class="notice notice-success is-dismissible"><p>Saved fields forgotten.</p></div>';
		}

		/* add / edit */
		if ( isset( $_POST['gasf_hero_add'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$image_id    = (int) ( $_POST['gasf_hero_image_id'] ?? 0 );
			$when_raw    = sanitize_text_field( wp_unslash( $_POST['gasf_hero_activate_at'] ?? '' ) );
			$dt          = $when_raw ? DateTime::createFromFormat( 'Y-m-d\TH:i', $when_raw, wp_timezone() ) : false;
			$ts          = $dt ? $dt->getTimestamp() : 0;
			$edit_id     = isset( $_POST['gasf_hero_edit_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gasf_hero_edit_id'] ) ) : '';

			if ( ! $image_id ) {
				echo '<div class="notice notice-error"><p>Please choose an image.</p></div>';
			} elseif ( ! $ts ) {
				echo '<div class="notice notice-error"><p>Please set a valid activation date/time.</p></div>';
			} else {
				$entries        = gasf_hero_get_entries();
				$sanitized_data = array(
					'image_id'     => $image_id,
					'image_url'    => esc_url_raw( wp_unslash( $_POST['gasf_hero_image_url'] ?? '' ) ),
					'max_width'    => max( 0, (int) ( $_POST['gasf_hero_max_width'] ?? 0 ) ),
					'caption'      => wp_kses_post( wp_unslash( $_POST['gasf_hero_caption'] ?? '' ) ),
					'button_label' => sanitize_text_field( wp_unslash( $_POST['gasf_hero_button_label'] ?? '' ) ),
					'button_url'   => esc_url_raw( wp_unslash( $_POST['gasf_hero_button_url'] ?? '' ) ),
					'activate_at'  => $ts,
					'event_id'     => (int) ( $_POST['gasf_hero_event_id'] ?? 0 ),
				);

				// Remember this event name's optional fields so the next similar
				// event's quick-create tile pre-fills them.
				gasf_hero_memory_remember( $sanitized_data['event_id'], $sanitized_data );

				// Purge the home cache when this hero's linked event ends, so the
				// fallback hero appears on time (activation purge is scheduled below).
				$exp_ts = gasf_hero_entry_expires( $sanitized_data );
				if ( $exp_ts > time() ) { gasf_hero_schedule_purge( $exp_ts + 60 ); }

				if ( $edit_id !== '' ) {
					// Edit in-place: find the matching entry and overwrite its editable fields.
					$found = false;
					foreach ( $entries as &$entry ) {
						if ( $entry['id'] === $edit_id ) {
							$entry = array_merge( $entry, $sanitized_data ); // keeps 'id' and 'created'
							$found = true;
							break;
						}
					}
					unset( $entry );

					if ( $found ) {
						gasf_hero_save_entries( $entries );
						gasf_hero_schedule_purge( $ts );
						echo '<div class="notice notice-success is-dismissible"><p>Hero updated.</p></div>';
					} else {
						// edit_id supplied but no matching entry found — fall through to create
						$entries[]  = array_merge( array( 'id' => uniqid( 'hero_', true ), 'created' => time() ), $sanitized_data );
						gasf_hero_save_entries( $entries );
						gasf_hero_schedule_purge( $ts );
						$verb = $ts > time() ? 'scheduled for' : 'live as of';
						echo '<div class="notice notice-success is-dismissible"><p>Hero ' . esc_html( $verb ) . ' ' . esc_html( wp_date( 'M j, Y g:i a', $ts ) ) . '.</p></div>';
					}
				} else {
					// Create: append new entry with a fresh id.
					$entries[] = array_merge( array( 'id' => uniqid( 'hero_', true ), 'created' => time() ), $sanitized_data );
					gasf_hero_save_entries( $entries );
					gasf_hero_schedule_purge( $ts );
					$verb = $ts > time() ? 'scheduled for' : 'live as of';
					echo '<div class="notice notice-success is-dismissible"><p>Hero ' . esc_html( $verb ) . ' ' . esc_html( wp_date( 'M j, Y g:i a', $ts ) ) . '.</p></div>';
				}
			}
		}

		$entries   = gasf_hero_get_entries();
		$now       = time();
		$active    = gasf_hero_active();
		$active_id = $active ? $active['id'] : '';
		$tz        = wp_timezone_string();
		// What [gas_hero] ACTUALLY shows: same filter chain as the front end.
		// Only this one row may say LIVE NOW — a due-but-outranked recurring
		// hero is "pending", not live (it can't be on the page).
		$winner              = apply_filters( 'gasf_hero_active_entry', $active );
		$winner_id           = ( is_array( $winner ) && isset( $winner['id'] ) ) ? $winner['id'] : '';
		$winner_is_recurring = is_array( $winner ) && ! isset( $winner['id'] );
		?>
			<h2>Home Page Hero</h2>
			<?php
			if ( function_exists( 'gasf_events_help_box' ) ) {
				gasf_events_help_box( array(
					'what'   => 'Schedules the big banner image at the top of the home page (rendered wherever the <code>[gas_hero]</code> shortcode sits). Heroes are a queue: each entry has a go-live time, and the newest entry whose time has passed is the one shown — so scheduling a future hero automatically replaces the current one at that moment, no midnight edits needed.',
					'needs'  => array(
						'The <code>[gas_hero]</code> shortcode on the home page (already in place).',
						'An image in the Media Library for each hero.',
					),
					'fields' => array(
						'Quick-create tiles'      => 'One tile per upcoming calendar event. Clicking a tile pre-fills the whole form from that event (cover image, link, and a go-live time 72 hours before it starts) — you just review and press Schedule. The "next N days" box only controls how far ahead the tiles look.',
						'&#8635; saved fields'    => 'When you schedule a hero linked to an event, its caption, button label and button link are remembered under that event\'s name. The next similar event (Dinner Night, a World Cup watch party…) pre-fills them on its quick-create tile — review before scheduling, since a date in the caption or an old ticket link may need updating. Manage/forget entries under "Remembered fields" below the tiles.',
						'Image'                   => 'The banner itself, picked from the Media Library. Required — a hero is fundamentally an image. Landscape images around 1200px wide look best.',
						'Image link (optional)'   => 'A URL that makes the entire image clickable — usually the event page or ticket link. Leave blank for a non-clickable banner.',
						'Caption (optional)'      => 'Short text shown under the image (basic HTML/links allowed). Use it for a date/tagline the image itself doesn\'t carry.',
						'Button label + link'     => 'Adds a call-to-action button below the caption (e.g. "Get Tickets"). The button link can differ from the image link. Both blank = no button.',
						'Go live on'              => 'When this hero takes over the home page, in site time. Set now/past to show immediately.',
					'End (automatic)'         => 'A hero linked to a calendar event (quick-create links it for you) retires automatically when that event ends — the previous still-valid hero returns. No linked event = shows indefinitely until a newer hero activates. The Status column shows "ends …" on the live hero and "ended" on retired ones.',
						'Advanced: display width' => 'Max rendered width in px, centered (default 450). Set 0 to span the full content width.',
					),
					'notes'  => 'Recurring events (Euchre Night, Krampus Meetup…) don\'t need manual entries — see the <strong>Recurring Heroes</strong> tab, which auto-shows a hero before each occurrence. Precedence: a manual hero linked to a still-running event holds the page until that event ends, then any due recurring hero takes over; unlinked heroes only outrank a recurring one if scheduled after its trigger.',
				) );
			}
			?>

			<?php
			$gasf_hero_days = (int) get_option( 'gasf_hero_lookahead_days', 14 );
			if ( $gasf_hero_days < 1 ) { $gasf_hero_days = 14; }
			$gasf_hero_up = gasf_hero_upcoming_events( $gasf_hero_days );
			?>
			<h3 class="title">Quick-create from an upcoming event</h3>
			<form method="post" style="margin:0 0 10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
				<?php wp_nonce_field( 'gasf_hero_action' ); ?>
				<label for="gasf_hero_days">Show events for the next</label>
				<input type="number" id="gasf_hero_days" name="gasf_hero_days" value="<?php echo (int) $gasf_hero_days; ?>" min="1" max="365" class="small-text" style="width:72px">
				<label>days</label>
				<button type="submit" name="gasf_hero_set_days" value="1" class="button">Update</button>
			</form>
			<?php if ( $gasf_hero_up ) : ?>
			<p>Click one to pre-fill the form below with its image &amp; link and a go-live time <strong>72&nbsp;hours before</strong> the event &mdash; then edit and schedule.</p>
			<div class="gasf-hero-up">
				<?php foreach ( $gasf_hero_up as $ev ) : ?>
					<button type="button" class="gasf-hero-up__item"
						data-image-id="<?php echo (int) $ev['image_id']; ?>"
						data-thumb="<?php echo esc_attr( $ev['thumb'] ); ?>"
						data-url="<?php echo esc_attr( $ev['url'] ); ?>"
						data-activate="<?php echo esc_attr( $ev['activate'] ); ?>"
						data-event-id="<?php echo (int) $ev['id']; ?>"
						data-caption="<?php echo esc_attr( $ev['mem_caption'] ); ?>"
						data-button-label="<?php echo esc_attr( $ev['mem_button_label'] ); ?>"
						data-button-url="<?php echo esc_attr( $ev['mem_button_url'] ); ?>">
						<?php if ( $ev['thumb'] ) : ?><img src="<?php echo esc_url( $ev['thumb'] ); ?>" alt=""><?php endif; ?>
						<span class="gasf-hero-up__t"><?php echo esc_html( $ev['title'] ); ?></span>
						<span class="gasf-hero-up__d"><?php echo esc_html( $ev['when'] ); ?></span>
						<?php if ( $ev['has_mem'] ) : ?><span class="gasf-hero-up__m" title="Pre-fills the caption/button saved from the last hero for this event">&#8635; saved fields</span><?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>
			<style>
				.gasf-hero-up{display:flex;flex-wrap:wrap;gap:10px;margin:6px 0 24px}
				.gasf-hero-up__item{cursor:pointer;width:150px;text-align:left;background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:8px;display:flex;flex-direction:column;gap:6px}
				.gasf-hero-up__item:hover{border-color:#2271b1;box-shadow:0 1px 4px rgba(0,0,0,.12)}
				.gasf-hero-up__item img{width:100%;height:90px;object-fit:cover;border-radius:4px;display:block}
				.gasf-hero-up__t{font-weight:600;font-size:12px;line-height:1.25}
				.gasf-hero-up__d{font-size:11px;color:#666}
				.gasf-hero-up__m{font-size:10px;color:#6d28d9;font-weight:600}
			</style>
			<?php else : ?>
			<p>No events in the next <?php echo (int) $gasf_hero_days; ?> days &mdash; increase the number above to look further out.</p>
			<?php endif; ?>

			<?php $gasf_hero_mem = gasf_hero_memory_all(); if ( $gasf_hero_mem ) : uasort( $gasf_hero_mem, function ( $a, $b ) { return ( $b['ts'] ?? 0 ) <=> ( $a['ts'] ?? 0 ); } ); ?>
			<details style="margin:0 0 18px;max-width:900px">
				<summary style="cursor:pointer;font-weight:600">&#8635; Remembered fields (<?php echo count( $gasf_hero_mem ); ?>)</summary>
				<p class="description" style="margin:6px 0">Saved automatically each time you schedule a hero linked to an event; the newest save for an event name wins. Quick-create tiles marked <span style="color:#6d28d9;font-weight:600">&#8635; saved fields</span> pre-fill from here.</p>
				<table class="widefat striped" style="margin-top:4px">
					<thead><tr><th>Event</th><th>Caption</th><th>Button</th><th>Saved</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $gasf_hero_mem as $mk => $mv ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $mv['title'] ?? $mk ); ?></strong></td>
							<td><?php echo '' !== ( $mv['caption'] ?? '' ) ? esc_html( wp_trim_words( wp_strip_all_tags( $mv['caption'] ), 10 ) ) : '<span style="color:#999">&mdash;</span>'; ?></td>
							<td><?php echo '' !== ( $mv['button_url'] ?? '' ) ? esc_html( ( '' !== ( $mv['button_label'] ?? '' ) ? $mv['button_label'] : 'Learn More' ) . ' → ' . $mv['button_url'] ) : '<span style="color:#999">&mdash;</span>'; ?></td>
							<td><?php echo ! empty( $mv['ts'] ) ? esc_html( wp_date( 'M j, Y', (int) $mv['ts'] ) ) : '&mdash;'; ?></td>
							<td>
								<form method="post" style="margin:0" onsubmit="return confirm('Forget the saved fields for this event name?');">
									<?php wp_nonce_field( 'gasf_hero_action' ); ?>
									<input type="hidden" name="gasf_hero_forget" value="<?php echo esc_attr( $mk ); ?>">
									<button type="submit" class="button-link-delete">Forget</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
			<?php endif; ?>

			<h3 class="title">Add / schedule a hero</h3>
			<form method="post">
				<?php wp_nonce_field( 'gasf_hero_action' ); ?>
				<input type="hidden" id="gasf_hero_edit_id" name="gasf_hero_edit_id" value="">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Image</th>
						<td>
							<input type="hidden" id="gasf_hero_image_id" name="gasf_hero_image_id" value="">
							<input type="hidden" id="gasf_hero_event_id" name="gasf_hero_event_id" value="">
							<div id="gasf_hero_preview" style="margin-bottom:8px;max-width:460px"></div>
							<button type="button" class="button" id="gasf_hero_pick">Choose image from Media Library</button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_image_url">Image link (optional)</label></th>
						<td><input type="url" id="gasf_hero_image_url" name="gasf_hero_image_url" class="regular-text" placeholder="https://…">
						<p class="description">Makes the whole image clickable. Can be different from the button link below.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_caption">Caption (optional)</label></th>
						<td><textarea id="gasf_hero_caption" name="gasf_hero_caption" rows="3" class="large-text" placeholder="Shown below the image. Basic HTML / links allowed."></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_button_label">Button label (optional)</label></th>
						<td><input type="text" id="gasf_hero_button_label" name="gasf_hero_button_label" class="regular-text" placeholder="e.g. Get Tickets"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_button_url">Button link (optional)</label></th>
						<td><input type="url" id="gasf_hero_button_url" name="gasf_hero_button_url" class="regular-text" placeholder="https://…">
						<p class="description">A URL here adds a button below the caption.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_activate_at">Go live on</label></th>
						<td>
							<input type="datetime-local" id="gasf_hero_activate_at" name="gasf_hero_activate_at" required>
							<p class="description">Site time (<?php echo esc_html( $tz ); ?>). Set to now or the past to show immediately.</p>
						</td>
					</tr>
				</table>
				<details id="gasf_hero_adv" style="margin:6px 0">
					<summary style="cursor:pointer">Advanced: display width</summary>
					<p style="margin:8px 0 0"><label for="gasf_hero_max_width">Display width</label> <input type="number" id="gasf_hero_max_width" name="gasf_hero_max_width" class="small-text" min="0" step="10" value="450"> px <span class="description">Max image width, centered. Set 0 for full width.</span></p>
				</details>
				<p>
					<button type="submit" id="gasf_hero_submit" name="gasf_hero_add" value="1" class="button button-primary">Schedule hero</button>
					<button type="button" id="gasf_hero_cancel_edit" class="button" style="display:none;margin-left:8px">Cancel edit</button>
				</p>
			</form>

			<h3 class="title">Scheduled heroes</h3>
			<table class="widefat striped">
				<thead><tr><th>Image</th><th>Goes live</th><th>Event end</th><th>Status</th><th>Links / caption</th><th></th></tr></thead>
				<tbody>
				<?php
				// Build ALL rows (recurring + manual) with their go-live timestamp,
				// then sort newest → oldest so the schedule reads top-to-bottom by
				// "Goes live" regardless of row type. Recurring rows are read-only
				// projections from the Recurring Heroes rules:
				//   • LIVE NOW — the recurring hero currently on the page (if any), so
				//     it never disappears from the schedule while it's active.
				//   • next recurring — the one that takes over after the furthest-out
				//     manual hero (a projection that recomputes as you add manuals).
				$rows = array(); // each: [ ts => int (go-live), html => string ]

				$active_rec = function_exists( 'gasf_hero_recurring_active' ) ? gasf_hero_recurring_active() : null;
				$next_rec   = null;
				if ( function_exists( 'gasf_hero_recurring_next_after' ) ) {
					$anchor = $now;
					foreach ( $entries as $e ) { $anchor = max( $anchor, (int) $e['activate_at'] ); }
					$next_rec = gasf_hero_recurring_next_after( $anchor );
				}
				if ( $active_rec ) {
					$rexp = isset( $active_rec['_expire_at'] ) ? (int) $active_rec['_expire_at'] : 0;
					if ( $winner_is_recurring ) {
						$rst = '<strong style="color:#1a7f37">&#9679; LIVE NOW</strong>'
							. ( $rexp > $now ? '<br><small style="color:#666">ends ' . esc_html( wp_date( 'M j, g:i a', $rexp ) ) . '</small>' : '' )
							. '<br><small style="color:#6d28d9">&#8635; recurring</small>';
					} else {
						// In window but outranked: a manual hero holds the page.
						$mexp = ( is_array( $winner ) ) ? gasf_hero_entry_expires( $winner ) : 0;
						$takeover = ( $mexp > $now && ( ! $rexp || $mexp < $rexp ) )
							? 'takes over ' . esc_html( wp_date( 'M j, g:i a', $mexp ) )
							: 'manual hero holds the page';
						$rst = '<span style="color:#b45309">&#10074;&#10074; pending</span>'
							. '<br><small style="color:#666">' . $takeover . '</small>'
							. '<br><small style="color:#6d28d9">&#8635; recurring</small>';
					}
					$rows[] = array( 'ts' => (int) $active_rec['activate_at'], 'html' => gasf_hero_recurring_row_html( $active_rec, $rst ) );
				}
				// Skip the projection if it's the same occurrence already shown as LIVE NOW.
				if ( $next_rec && ( ! $active_rec || (int) ( $next_rec['event_id'] ?? 0 ) !== (int) ( $active_rec['event_id'] ?? 0 ) ) ) {
					$rows[] = array( 'ts' => (int) $next_rec['activate_at'], 'html' => gasf_hero_recurring_row_html( $next_rec, '<span style="color:#6d28d9" title="Fires automatically from the Recurring Heroes rule">&#8635; next recurring</span>' ) );
				}

				foreach ( $entries as $e ) {
					$ts  = (int) $e['activate_at'];
					$exp = gasf_hero_entry_expires( $e );
					if ( $e['id'] !== '' && $e['id'] === $winner_id ) {
						$status = '<strong style="color:#1a7f37">&#9679; LIVE NOW</strong>'
							. ( $exp > $now ? '<br><small style="color:#666">ends ' . esc_html( wp_date( 'M j, g:i a', $exp ) ) . '</small>' : '' );
					} elseif ( $e['id'] === $active_id && $winner_is_recurring ) {
						// Newest valid manual, but the recurring hero outranks it.
						$status = '<span style="color:#b45309">&#10074;&#10074; overridden</span><br><small style="color:#666">recurring hero has the page</small>';
					} elseif ( $exp > 0 && $exp <= $now ) {
						$status = '<span style="color:#b45309" title="Linked event has ended — this hero retired automatically">ended</span>';
					} elseif ( $ts > $now ) {
						$status = '<span style="color:#8250df">queued</span>';
					} else {
						$status = '<span style="color:#888">past</span>';
					}
					$thumb   = wp_get_attachment_image( (int) $e['image_id'], array( 90, 90 ) );
					$img_url = isset( $e['image_url'] ) ? $e['image_url'] : '';
					ob_start();
					?>
					<tr>
						<td><?php echo $thumb ? $thumb : '#' . (int) $e['image_id']; // phpcs:ignore ?></td>
						<td><?php echo esc_html( wp_date( 'M j, Y g:i a', $ts ) ); ?></td>
						<td><?php echo gasf_hero_event_end_label( isset( $e['event_id'] ) ? (int) $e['event_id'] : 0 ); // phpcs:ignore ?></td>
						<td><?php echo $status; // phpcs:ignore ?></td>
						<td>
							<?php
							if ( $img_url !== '' ) { echo '<small>image &rarr; ' . esc_html( $img_url ) . '</small><br>'; }
							echo $e['caption'] !== '' ? esc_html( wp_trim_words( wp_strip_all_tags( $e['caption'] ), 12 ) ) : ( $img_url !== '' ? '' : '—' );
							if ( isset( $e['button_url'] ) && $e['button_url'] !== '' ) {
								echo '<br><small>button: ' . esc_html( $e['button_label'] !== '' ? $e['button_label'] : 'Learn More' ) . ' &rarr; ' . esc_html( $e['button_url'] ) . '</small>';
							}
							?>
						</td>
						<td style="white-space:nowrap">
							<button type="button" class="button gasf-hero-edit"
								data-id="<?php echo esc_attr( $e['id'] ); ?>"
								data-image-id="<?php echo esc_attr( $e['image_id'] ); ?>"
								data-event-id="<?php echo esc_attr( isset( $e['event_id'] ) ? $e['event_id'] : '' ); ?>"
								data-image-url="<?php echo esc_attr( isset( $e['image_url'] ) ? $e['image_url'] : '' ); ?>"
								data-max-width="<?php echo esc_attr( isset( $e['max_width'] ) ? $e['max_width'] : '' ); ?>"
								data-caption="<?php echo esc_attr( isset( $e['caption'] ) ? $e['caption'] : '' ); ?>"
								data-button-label="<?php echo esc_attr( isset( $e['button_label'] ) ? $e['button_label'] : '' ); ?>"
								data-button-url="<?php echo esc_attr( isset( $e['button_url'] ) ? $e['button_url'] : '' ); ?>"
								data-thumb="<?php echo esc_attr( wp_get_attachment_image_url( (int) $e['image_id'], 'large' ) ); ?>"
								data-activate="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', $ts ) ); ?>"
								style="margin-bottom:4px">Edit</button>
							<form method="post" onsubmit="return confirm('Delete this hero entry?');" style="margin:0">
								<?php wp_nonce_field( 'gasf_hero_action' ); ?>
								<input type="hidden" name="gasf_hero_delete" value="<?php echo esc_attr( $e['id'] ); ?>">
								<button type="submit" class="button-link-delete">Delete</button>
							</form>
						</td>
					</tr>
					<?php
					$rows[] = array( 'ts' => $ts, 'html' => ob_get_clean() );
				}

				// Newest → oldest by go-live time, across recurring + manual alike.
				usort( $rows, function ( $a, $b ) { return (int) $b['ts'] <=> (int) $a['ts']; } );

				if ( ! $rows ) {
					echo '<tr><td colspan="6">No heroes scheduled yet.</td></tr>';
				} else {
					foreach ( $rows as $r ) { echo $r['html']; } // phpcs:ignore
				}
				?>
				</tbody>
			</table>
			<p class="description" style="margin-top:6px">Rows with <span style="color:#6d28d9">&#8635;</span> come from the <strong>Recurring Heroes</strong> rules: a <strong style="color:#1a7f37">&#9679; LIVE NOW &#8635; recurring</strong> row shows while one is the active hero, and the <span style="color:#6d28d9">&#8635; next recurring</span> row previews the one that takes over after your last hand-scheduled hero. Their <em>Edit</em> button jumps straight to that rule.</p>
		<script>
		jQuery(function($){
			$('#gasf_hero_pick').on('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title:'Select hero image', multiple:false, library:{ type:'image' }, button:{ text:'Use this image' } });
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					$('#gasf_hero_image_id').val(a.id);
					var url = (a.sizes && a.sizes.large) ? a.sizes.large.url : ((a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url);
					$('#gasf_hero_preview').html('<img src="'+url+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">');
				});
				frame.open();
			});

			// Quick-create: clicking an upcoming-event card pre-fills the form below.
			// Caption/button come from the field memory saved with the last hero
			// for a similarly-named event (empty when there is none — also clears
			// leftovers from a previously clicked tile).
			$('.gasf-hero-up__item').on('click', function(){
				var b = $(this);
				$('#gasf_hero_image_id').val( b.data('image-id') || '' );
				$('#gasf_hero_event_id').val( b.data('event-id') || '' );
				$('#gasf_hero_image_url').val( b.data('url') || '' );
				$('#gasf_hero_activate_at').val( b.data('activate') || '' );
				$('#gasf_hero_caption').val( b.attr('data-caption') || '' );      // .attr() — preserves HTML
				$('#gasf_hero_button_label').val( b.attr('data-button-label') || '' );
				$('#gasf_hero_button_url').val( b.attr('data-button-url') || '' );
				var t = b.data('thumb');
				if ( t ) { $('#gasf_hero_preview').html('<img src="'+t+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">'); }
				$('html,body').animate({ scrollTop: $('#gasf_hero_activate_at').closest('table').offset().top - 80 }, 300);
			});

			// Edit hero: pre-fill the form from the row's data-* attributes.
			$(document).on('click', '.gasf-hero-edit', function(){
				var b = $(this);
				$('#gasf_hero_edit_id').val( b.attr('data-id') );
				$('#gasf_hero_image_id').val( b.attr('data-image-id') || '' );
				$('#gasf_hero_event_id').val( b.attr('data-event-id') || '' );
				$('#gasf_hero_image_url').val( b.attr('data-image-url') || '' );
				$('#gasf_hero_max_width').val( b.attr('data-max-width') || '' );
				if ( ( b.attr('data-max-width') || '' ) !== '450' ) { $('#gasf_hero_adv').attr('open', true); } else { $('#gasf_hero_adv').removeAttr('open'); }
				$('#gasf_hero_caption').val( b.attr('data-caption') );  // .attr() not .data() — preserves HTML
				$('#gasf_hero_button_label').val( b.attr('data-button-label') || '' );
				$('#gasf_hero_button_url').val( b.attr('data-button-url') || '' );
				$('#gasf_hero_activate_at').val( b.attr('data-activate') || '' );
				var thumb = b.attr('data-thumb');
				if ( thumb ) {
					$('#gasf_hero_preview').html('<img src="'+thumb+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">');
				} else {
					$('#gasf_hero_preview').html('');
				}
				$('#gasf_hero_submit').text('Save changes');
				$('#gasf_hero_cancel_edit').show();
				$('html,body').animate({ scrollTop: $('#gasf_hero_activate_at').closest('table').offset().top - 80 }, 300);
			});

			// Cancel edit: reset form to create mode.
			$('#gasf_hero_cancel_edit').on('click', function(){
				$('#gasf_hero_edit_id').val('');
				$('#gasf_hero_image_id').val('');
				$('#gasf_hero_event_id').val('');
				$('#gasf_hero_image_url').val('');
				$('#gasf_hero_max_width').val('450');
				$('#gasf_hero_adv').removeAttr('open');
				$('#gasf_hero_caption').val('');
				$('#gasf_hero_button_label').val('');
				$('#gasf_hero_button_url').val('');
				$('#gasf_hero_activate_at').val('');
				$('#gasf_hero_preview').html('');
				$('#gasf_hero_submit').text('Schedule hero');
				$(this).hide();
			});
		});
		</script>
		<?php
	}
}
