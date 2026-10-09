<?php
/**
 * GBP_Notice — tells somebody when the Business Profile hours sync has stopped
 * working.
 *
 * Without this the failure is completely silent: the cron runs, Google refuses,
 * the run returns an error nobody reads, and the published hours quietly freeze
 * at whatever was last accepted. They stay plausible-looking for weeks, which is
 * worse than being obviously broken.
 *
 * Deliberately NOT dismissible. A dismissed notice for a thing still broken is
 * just a quieter silence; this one disappears when the next run succeeds and not
 * before.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class GBP_Notice {

	/** Screens worth interrupting: the dashboard, and anywhere events are managed. */
	const SCREENS = [ 'dashboard', 'edit-' . GASF_EVENTS_CPT, GASF_EVENTS_CPT ];

	/** Remembers which failure we already emailed about, so one break = one email. */
	const OPT_MAILED = 'gasf_events_gbp_mailed';

	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'gasf_events_gbp_push', [ __CLASS__, 'maybe_email' ], 99 );
		add_action( 'gasf_events_gbp_daily', [ __CLASS__, 'maybe_email' ], 99 );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}
		$h = Hours_Sync::health();
		if ( $h['ok'] ) {
			return;
		}
		[ $headline, $advice ] = self::explain( $h );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Google Business Profile hours are not updating.', 'gasf-events' ); ?></strong>
				<?php echo esc_html( $headline ); ?>
			</p>
			<p><?php echo wp_kses_post( $advice ); ?></p>
			<?php if ( '' !== (string) $h['message'] ) : ?>
				<p><code style="font-size:11px;"><?php echo esc_html( $h['message'] ); ?></code></p>
			<?php endif; ?>
			<p style="color:#646970;">
				<?php
				printf(
					/* translators: 1: what is still published, 2: how long */
					esc_html__( 'Google is still showing the hours from the last successful sync %1$s. They will drift further out of date until this is fixed.', 'gasf-events' ),
					$h['since'] ? esc_html( sprintf( '(%s ago)', human_time_diff( (int) $h['since'] ) ) ) : ''
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * A headline and an actionable next step per failure kind. The raw API string
	 * is shown too, but underneath — "HTTP 403: Caller does not have required
	 * permission" tells an admin nothing about what to go and click.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function explain( array $h ): array {
		$fails = (int) ( $h['fails'] ?? 0 );
		$n     = $fails > 1 ? sprintf( ' (%d runs in a row)', $fails ) : '';

		switch ( $h['kind'] ) {
			case 'auth':
				return [
					'Google rejected the saved credentials' . $n . '.',
					'The refresh token is no longer valid. The usual cause is the OAuth consent screen reverting to <strong>Testing</strong>, which revokes tokens after 7 days — check it is still <strong>External + In production</strong>. A new token has to be minted and written to the key file; see <code>docs/GBP-HOURS-SYNC.md</code> §3.',
				];
			case 'quota':
				return [
					'The API quota was exhausted' . $n . '.',
					'Requests are being refused with 429. Check <em>Requests per minute</em> on both Business Profile APIs in the allowlisted Cloud project — if it reads <strong>0</strong>, the allowlisting has been lost rather than merely used up.',
				];
			case 'permission':
				return [
					'Google refused the request as unauthorised' . $n . '.',
					'A 403 usually means the quota-project grant was removed — the calling account needs <code>roles/serviceusage.serviceUsageConsumer</code> on the allowlisted project. It can also mean the listing is no longer managed by that Google account.',
				];
			case 'missing':
				return [
					'The listing could not be found' . $n . '.',
					'A 404 on the location. It may have been merged, moved to another account, or the stored location ID is wrong.',
				];
			case 'config':
				return [
					'The sync is not configured correctly' . $n . '.',
					'Either the location ID is unset or the credentials file cannot be read on the server. Nothing has been sent to Google.',
				];
			case 'stale':
				return [
					'No successful sync for over 48 hours.',
					'No error was recorded, which points at the scheduler rather than Google — WP-Cron may not be firing. Saving any event should queue a run within a couple of minutes; if that does nothing, cron is the problem.',
				];
			default:
				return [
					'The last sync failed' . $n . '.',
					'The error from Google is below.',
				];
		}
	}

	/**
	 * Email on the way into a failure and on the way out, never in between.
	 *
	 * A revoked token fails on every event save; mailing each one would train
	 * everybody to filter the alerts, which is the same as having none.
	 */
	public static function maybe_email(): void {
		$to = Alerts::email();
		if ( '' === $to ) {
			return;
		}
		$h      = Hours_Sync::health();
		$mailed = (string) get_option( self::OPT_MAILED, '' );
		$state  = $h['ok'] ? '' : (string) $h['kind'];

		if ( $state === $mailed ) {
			return; // nothing changed since the last message
		}
		update_option( self::OPT_MAILED, $state, false );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === $state ) {
			wp_mail(
				$to,
				sprintf( '[%s] Google hours sync is working again', $site ),
				"The Business Profile hours sync completed successfully.\n\nNothing to do."
			);
			return;
		}
		[ $headline, $advice ] = self::explain( $h );
		wp_mail(
			$to,
			sprintf( '[%s] Google hours sync is failing', $site ),
			$headline . "\n\n"
			. wp_strip_all_tags( $advice ) . "\n\n"
			. ( '' !== (string) $h['message'] ? "Reported by Google:\n" . $h['message'] . "\n\n" : '' )
			. "Google is still showing the hours from the last successful sync, and they will drift out of date until this is fixed.\n\n"
			. admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT )
		);
	}
}
