<?php
/**
 * Events → Health: one screen for everything that runs on its own.
 *
 * Feed sync, the Eventbrite publish queue, Business Profile hours, the hero
 * purges, the update check and the cache purge each reported in a different
 * place or not at all, and every one of them depends on WP-Cron firing — on a
 * quiet site WP-Cron fires only when someone loads a page. The worst failure
 * is the silent one: nothing errors, the jobs just stop, and the site keeps
 * looking fine while drifting.
 *
 * So this also runs an hourly heartbeat. If the heartbeat is late, cron is
 * dead, whatever the individual jobs say; that is reported on the dashboard
 * and emailed once per outage (from a page load, since cron cannot mail
 * about its own death).
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Health {

	const SLUG          = 'gasf-events-health';
	const CRON_HOOK     = 'gasf_events_heartbeat';
	const OPT_BEAT      = 'gasf_events_heartbeat';        // last heartbeat unix time
	const OPT_MAILED    = 'gasf_events_heartbeat_mailed'; // '' healthy | 'dead' mailed
	const CHECK_LOCK    = 'gasf_events_heartbeat_check';  // transient: throttle page-load checks

	/** Hourly beat; late by this much means cron is not running. */
	const STALE_AFTER = 3 * HOUR_IN_SECONDS;

	/** Screens that get the "cron is dead" notice. */
	const SCREENS = [ 'dashboard', 'edit-' . GASF_EVENTS_CPT, GASF_EVENTS_CPT ];

	public function register_hooks(): void {
		add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'beat' ] );
		add_action( 'admin_init', [ __CLASS__, 'check_from_page_load' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_gasf_health_purge', [ $this, 'action_purge' ] );
		add_action( 'admin_post_gasf_health_test_mail', [ $this, 'action_test_mail' ] );
	}

	/* ---- heartbeat ---------------------------------------------------- */

	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::CRON_HOOK );
		}
	}

	public static function beat(): void {
		update_option( self::OPT_BEAT, time(), false );
	}

	/**
	 * Cron is alive when the last beat is recent. Before the first beat
	 * (fresh install) nothing is known yet, which is reported as such rather
	 * than as a failure.
	 *
	 * @return array{state:'ok'|'stale'|'dead'|'unknown', last:int}
	 */
	public static function cron_state(): array {
		$last = (int) get_option( self::OPT_BEAT, 0 );
		if ( ! $last ) {
			return [ 'state' => 'unknown', 'last' => 0 ];
		}
		$age = time() - $last;
		if ( $age > self::STALE_AFTER ) {
			return [ 'state' => 'dead', 'last' => $last ];
		}
		if ( $age > 90 * MINUTE_IN_SECONDS ) {
			return [ 'state' => 'stale', 'last' => $last ];
		}
		return [ 'state' => 'ok', 'last' => $last ];
	}

	/**
	 * Runs on admin page loads, at most once an hour: if the heartbeat says
	 * cron is dead, mail the alert address once; when it recovers, mail once
	 * more. The check lives here because a dead cron cannot report itself.
	 */
	public static function check_from_page_load(): void {
		if ( get_transient( self::CHECK_LOCK ) ) {
			return;
		}
		set_transient( self::CHECK_LOCK, 1, HOUR_IN_SECONDS );
		$state  = self::cron_state()['state'];
		$mailed = (string) get_option( self::OPT_MAILED, '' );
		$now    = 'dead' === $state ? 'dead' : '';
		if ( 'unknown' === $state || $now === $mailed ) {
			return;
		}
		update_option( self::OPT_MAILED, $now, false );
		if ( 'dead' === $now ) {
			Alerts::send(
				'WP-Cron has stopped — scheduled jobs are not running',
				"The hourly heartbeat last ran " . human_time_diff( (int) get_option( self::OPT_BEAT, 0 ) ) . " ago.\n\n"
				. "Nothing scheduled is running: feed sync, the Eventbrite publish queue, Business Profile hours, hero purges. The site still serves pages; it is just not updating.\n\n"
				. "Usual cause on a quiet site: WP-Cron only fires on page loads. Fix for good with a real cron entry — see Events → Health for the exact command.\n\n"
				. self::url()
			);
		} else {
			Alerts::send( 'WP-Cron is running again', "The heartbeat has resumed. Nothing to do.\n\n" . self::url() );
		}
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}
		$cron = self::cron_state();
		if ( 'dead' !== $cron['state'] ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Scheduled jobs are not running.', 'gasf-events' ),
			esc_html( sprintf(
				/* translators: %s: how long ago */
				__( 'The WP-Cron heartbeat last ran %s ago, so feed sync, Eventbrite publishing and Google hours are all stalled.', 'gasf-events' ),
				human_time_diff( $cron['last'] )
			) ),
			esc_url( self::url() ),
			esc_html__( 'Open Events → Health', 'gasf-events' )
		);
	}

	/* ---- the screen --------------------------------------------------- */

	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=' . self::SLUG );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . GASF_EVENTS_CPT,
			__( 'Events Health', 'gasf-events' ),
			__( 'Health', 'gasf-events' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function action_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( 'gasf_health_purge' );
		$done = Cache::purge();
		wp_safe_redirect( add_query_arg( 'gasf_msg', rawurlencode( sprintf(
			/* translators: %s: list of cache names */
			__( 'Purged: %s.', 'gasf-events' ),
			implode( ', ', $done )
		) ), self::url() ) );
		exit;
	}

	public function action_test_mail(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( 'gasf_health_test_mail' );
		$ok  = Alerts::send( 'Test alert from Events → Health', "If you are reading this, alert email works.\n\n" . self::url() );
		$msg = $ok
			? sprintf( /* translators: %s: email address */ __( 'Test email sent to %s.', 'gasf-events' ), Alerts::email() )
			: __( 'No alert email is configured (Events → Settings → Sync alerts), or wp_mail() refused.', 'gasf-events' );
		wp_safe_redirect( add_query_arg( 'gasf_msg', rawurlencode( $msg ), self::url() ) );
		exit;
	}

	/**
	 * Every row on the screen. Each is [ label, state, detail ], state one of
	 * ok | warn | bad | off | unknown. Public so WP-CLI or a dashboard widget
	 * can read the same facts.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function rows(): array {
		$rows = [];
		$ago  = static fn( int $ts ): string => $ts ? sprintf( /* translators: %s: elapsed time */ __( '%s ago', 'gasf-events' ), human_time_diff( $ts ) ) : __( 'never', 'gasf-events' );
		$at   = static fn( int $ts ): string => $ts ? wp_date( 'M j, g:i a', $ts ) : '—';

		// 1. Cron.
		$cron = self::cron_state();
		$map  = [ 'ok' => 'ok', 'stale' => 'warn', 'dead' => 'bad', 'unknown' => 'unknown' ];
		$rows[] = [
			__( 'WP-Cron heartbeat', 'gasf-events' ),
			$map[ $cron['state'] ],
			'unknown' === $cron['state']
				? __( 'No heartbeat recorded yet — the first runs within the hour.', 'gasf-events' )
				: sprintf( __( 'Last beat %1$s · next due %2$s', 'gasf-events' ), $ago( $cron['last'] ), $at( (int) wp_next_scheduled( self::CRON_HOOK ) ) ),
		];
		$rows[] = [
			__( 'Cron trigger', 'gasf-events' ),
			( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'ok' : 'warn',
			( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
				? __( 'DISABLE_WP_CRON is set: a system cron is expected to call wp-cron.php. Good.', 'gasf-events' )
				: __( 'WP-Cron fires from page loads only. On a quiet site the jobs below can wait hours. See the cron setup box below.', 'gasf-events' ),
		];

		// 2. Feed sync.
		$last  = (array) get_option( Feeds::OPT_LAST_RUN, [] );
		$lts   = (int) ( $last['ts'] ?? 0 );
		$errs  = (array) ( $last['errors'] ?? [] );
		$next  = (int) wp_next_scheduled( Feeds::CRON_HOOK );
		if ( ! Feeds::enabled() ) {
			$rows[] = [ __( 'Feed sync', 'gasf-events' ), 'off', __( 'Automatic sync is off (Events → Feeds).', 'gasf-events' ) ];
		} else {
			$state = 'ok';
			if ( $errs ) {
				$state = 'warn';
			}
			if ( ! $lts || ( time() - $lts ) > 2 * HOUR_IN_SECONDS ) {
				$state = 'bad';
			}
			$rows[] = [
				__( 'Feed sync', 'gasf-events' ),
				$state,
				sprintf(
					/* translators: 1: last run, 2: counts, 3: next run, 4: error text */
					__( 'Last run %1$s (%2$s) · next %3$s%4$s', 'gasf-events' ),
					$ago( $lts ),
					$lts ? sprintf( 'created %d, updated %d, drafted %d', (int) ( $last['created'] ?? 0 ), (int) ( $last['updated'] ?? 0 ), (int) ( $last['drafted'] ?? 0 ) ) : '—',
					$at( $next ),
					$errs ? ' · ' . __( 'errors:', 'gasf-events' ) . ' ' . implode( ' | ', array_map( 'strval', $errs ) ) : ''
				),
			];
		}
		$days = Feeds::soonest_expiry_days();
		if ( null !== $days ) {
			$rows[] = [
				__( 'Facebook token', 'gasf-events' ),
				$days < 0 ? 'bad' : ( $days < 14 ? 'warn' : 'ok' ),
				$days < 0
					? sprintf( __( 'Expired %d days ago. Mint a new page token on Events → Feeds.', 'gasf-events' ), -$days )
					: sprintf( __( 'Soonest expiry in %d days.', 'gasf-events' ), $days ),
			];
		}

		// 3. Publish queue (Eventbrite).
		$queue = (array) get_option( Syndication::QUEUE, [] );
		$drain = (int) wp_next_scheduled( Syndication::DRAIN_HOOK );
		if ( ! Eventbrite::enabled() ) {
			$rows[] = [ __( 'Eventbrite publishing', 'gasf-events' ), 'off', __( 'Disabled or not configured (Events → Feeds).', 'gasf-events' ) ];
		} else {
			$rows[] = [
				__( 'Eventbrite publishing', 'gasf-events' ),
				( $queue && ! $drain ) ? 'warn' : 'ok',
				$queue
					? sprintf( __( '%1$d event(s) waiting · drain %2$s', 'gasf-events' ), count( $queue ), $drain ? $at( $drain ) : __( 'not scheduled', 'gasf-events' ) )
					: __( 'Queue empty.', 'gasf-events' ),
			];
		}

		// 4. Google Business Profile hours.
		if ( ! Hours_Sync::enabled() ) {
			$rows[] = [ __( 'Google Business Profile hours', 'gasf-events' ), 'off', __( 'Disabled.', 'gasf-events' ) ];
		} else {
			$h = Hours_Sync::health();
			$l = (array) get_option( Hours_Sync::OPT_LAST, [] );
			$rows[] = [
				__( 'Google Business Profile hours', 'gasf-events' ),
				$h['ok'] ? 'ok' : 'bad',
				$h['ok']
					? sprintf( __( 'Last push %1$s (%2$d periods) · daily sweep %3$s', 'gasf-events' ), $ago( (int) ( $l['ts'] ?? 0 ) ), (int) ( $l['periods'] ?? 0 ), $at( (int) wp_next_scheduled( Hours_Sync::CRON_DAILY ) ) )
					: sprintf( __( 'Failing (%1$s): %2$s', 'gasf-events' ), (string) $h['kind'], (string) $h['message'] ),
			];
		}

		// 5. Heroes.
		if ( Settings::heroes_enabled() ) {
			$hn = (int) wp_next_scheduled( 'gasf_hero_recurring_cron' );
			$rows[] = [ __( 'Recurring heroes', 'gasf-events' ), $hn ? 'ok' : 'warn', sprintf( __( 'Hourly purge scheduler · next %s', 'gasf-events' ), $at( $hn ) ) ];
		}

		// 5b. Webhooks.
		$wh = Webhooks::enabled_hooks();
		if ( $wh ) {
			$bad = Webhooks::failing();
			$wq  = Webhooks::queue_length();
			$rows[] = [
				__( 'Webhooks', 'gasf-events' ),
				$bad ? 'bad' : ( $wq ? 'warn' : 'ok' ),
				$bad
					? sprintf( __( '%1$d of %2$d receiver(s) failing: %3$s', 'gasf-events' ), count( $bad ), count( $wh ), implode( ' | ', array_map( static fn( $h ) => ( $h['label'] ?: $h['url'] ) . ' — ' . $h['last_error'], $bad ) ) )
					: sprintf( __( '%1$d receiver(s) · %2$d waiting to retry', 'gasf-events' ), count( $wh ), $wq ),
			];
		}

		// 6. Cache purge.
		$p = (array) get_option( Cache::OPT_LAST, [] );
		$rows[] = [
			__( 'Page cache purge', 'gasf-events' ),
			'ok',
			empty( $p['ts'] )
				? __( 'No purge recorded yet (runs on every event change and feed run).', 'gasf-events' )
				: sprintf( __( 'Last purge %1$s · reached: %2$s', 'gasf-events' ), $ago( (int) $p['ts'] ), implode( ', ', (array) ( $p['targets'] ?? [] ) ) ),
		];

		// 7. Updates.
		$remote = (string) get_transient( Updater::CACHE_KEY );
		$rows[] = [
			__( 'Plugin version', 'gasf-events' ),
			( '' !== $remote && version_compare( $remote, GASF_EVENTS_VERSION, '>' ) ) ? 'warn' : 'ok',
			sprintf(
				/* translators: 1: installed version, 2: where updates come from, 3: the version found there */
				__( 'Installed %1$s · %2$s %3$s', 'gasf-events' ),
				GASF_EVENTS_VERSION,
				'main' === Updater::channel() ? __( 'GitHub main branch', 'gasf-events' ) : __( 'latest GitHub release', 'gasf-events' ),
				'' !== $remote ? $remote : __( 'not found yet (none published, or not checked)', 'gasf-events' )
			),
		];

		// 8. Assets on disk — the Alpine file was missing for several releases.
		$missing = [];
		foreach ( [ 'assets/css/gasf-events.css', 'assets/js/gasf-events.js', 'assets/js/vendor/alpine.min.js' ] as $rel ) {
			if ( ! is_readable( GASF_EVENTS_DIR . $rel ) ) {
				$missing[] = $rel;
			}
		}
		$rows[] = [
			__( 'Front-end assets', 'gasf-events' ),
			$missing ? 'bad' : 'ok',
			$missing ? sprintf( __( 'Missing: %s', 'gasf-events' ), implode( ', ', $missing ) ) : __( 'CSS, JS and Alpine present.', 'gasf-events' ),
		];

		// 9. Database indexes.
		$idx = Post_Type::indexes_present();
		$rows[] = [
			__( 'Database indexes', 'gasf-events' ),
			$idx['missing'] ? 'warn' : 'ok',
			$idx['missing']
				? sprintf( __( 'Missing %s — every calendar query sorts the whole meta table. Run: wp gasf-events ensure-indexes', 'gasf-events' ), implode( ', ', $idx['missing'] ) )
				: __( 'gasf_start_ts and gasf_end_ts present.', 'gasf-events' ),
		];

		// 10. Alerts.
		$email = Alerts::email();
		$rows[] = [
			__( 'Alert email', 'gasf-events' ),
			'' === $email ? 'warn' : 'ok',
			'' === $email ? __( 'Not set — failures are shown here but nobody is emailed.', 'gasf-events' ) : $email,
		];

		return $rows;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rows   = self::rows();
		$msg    = isset( $_GET['gasf_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$u      = esc_url( admin_url( 'admin-post.php' ) );
		$labels = [
			'ok'      => [ '#1a7f37', '●', __( 'OK', 'gasf-events' ) ],
			'warn'    => [ '#b45309', '▲', __( 'Attention', 'gasf-events' ) ],
			'bad'     => [ '#b32d2e', '✖', __( 'Failing', 'gasf-events' ) ],
			'off'     => [ '#888',    '○', __( 'Off', 'gasf-events' ) ],
			'unknown' => [ '#888',    '?', __( 'Unknown', 'gasf-events' ) ],
		];
		$cron_cmd = sprintf( '*/5 * * * * curl -s "%s" >/dev/null 2>&1', site_url( '/wp-cron.php?doing_wp_cron' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Events Health', 'gasf-events' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Everything in this plugin that runs by itself, in one place. Red means it is not doing its job; amber means it needs a look.', 'gasf-events' ); ?></p>

			<table class="widefat striped" style="max-width:980px;">
				<thead><tr><th style="width:220px;"><?php esc_html_e( 'Job', 'gasf-events' ); ?></th><th style="width:110px;"><?php esc_html_e( 'State', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Detail', 'gasf-events' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : [ $label, $state, $detail ] = $r; $l = $labels[ $state ] ?? $labels['unknown']; ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><span style="color:<?php echo esc_attr( $l[0] ); ?>;font-weight:600;"><?php echo esc_html( $l[1] . ' ' . $l[2] ); ?></span></td>
						<td><?php echo esc_html( $detail ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:14px;">
				<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_health_purge' ); ?><input type="hidden" name="action" value="gasf_health_purge"><?php submit_button( __( 'Purge page caches now', 'gasf-events' ), 'secondary', '', false ); ?></form>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;margin-left:6px;"><?php wp_nonce_field( 'gasf_health_test_mail' ); ?><input type="hidden" name="action" value="gasf_health_test_mail"><?php submit_button( __( 'Send a test alert email', 'gasf-events' ), 'secondary', '', false ); ?></form>
			</p>

			<h2><?php esc_html_e( 'Make cron reliable', 'gasf-events' ); ?></h2>
			<p class="description" style="max-width:900px;">
				<?php esc_html_e( 'WordPress runs its scheduled jobs only when a visitor loads a page. Overnight, or on a quiet weekday, nothing fires — the feed sync, Eventbrite publishing, the Google hours push and the hero purges all wait. The fix is a real cron entry on the host that calls wp-cron.php every five minutes, and a line in wp-config.php that stops page loads from also trying.', 'gasf-events' ); ?>
			</p>
			<ol style="max-width:900px;">
				<li><?php esc_html_e( 'In cPanel → Cron Jobs, add this entry (every 5 minutes):', 'gasf-events' ); ?>
					<pre style="background:#f6f7f7;padding:8px 12px;overflow:auto;"><?php echo esc_html( $cron_cmd ); ?></pre></li>
				<li><?php esc_html_e( 'In wp-config.php, above the "That\'s all, stop editing" line, add:', 'gasf-events' ); ?>
					<pre style="background:#f6f7f7;padding:8px 12px;"><?php echo esc_html( "define( 'DISABLE_WP_CRON', true );" ); ?></pre></li>
				<li><?php esc_html_e( 'Come back here after an hour: the heartbeat row should read OK and the Cron trigger row should say a system cron is expected.', 'gasf-events' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
