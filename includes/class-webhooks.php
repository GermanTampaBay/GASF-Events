<?php
/**
 * Outbound webhooks: tell the road sign and the kiosk the moment the calendar
 * changes, instead of making them poll for it.
 *
 * A consumer registers a URL (Events → Webhooks) and gets one HTTPS POST per
 * request that changed anything — a feed run that touches forty events is
 * one delivery, not forty — listing each event as `updated` (published or
 * changed while published) or `removed` (unpublished, trashed, deleted, or
 * drafted by the sync). The body is signed with the hook's secret
 * (HMAC-SHA256) so the receiver can prove it came from here.
 *
 * Delivery is at-least-once: a failed POST is queued and retried with
 * backoff (2, 4, 8, 16, 32 minutes) through one WP-Cron event, then dropped
 * and the failure shown on the Webhooks and Health screens. A receiver that
 * wants certainty keeps polling /events/changes as well; the webhook is the
 * fast path, the delta feed is the safety net. See docs/REST-API.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Webhooks {

	const OPTION     = 'gasf_events_webhooks';       // [ { id, url, secret, enabled, created, last_ts, last_status, last_error } ]
	const QUEUE      = 'gasf_events_webhook_queue';  // [ { hook, delivery, body, event, attempts, next } ]
	const RETRY_HOOK = 'gasf_events_webhook_retry';
	const SLUG       = 'gasf-events-webhooks';

	/** First attempt + 5 retries; backoff doubles from 2 minutes. */
	const MAX_ATTEMPTS = 6;
	const TIMEOUT      = 8; // seconds per POST

	/** Compact event shape carried in an `updated` change. */
	const CHANGE_FIELDS = [ 'id', 'title', 'short_title', 'start', 'end', 'all_day', 'status', 'featured', 'priority', 'url', 'modified' ];

	/** @var array<int,string> post id => 'updated' | 'removed', collected this request. */
	private static $changes = [];

	public function register_hooks(): void {
		add_action( 'save_post_' . GASF_EVENTS_CPT, [ __CLASS__, 'on_save' ], 40, 3 );
		add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 40, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ], 40, 2 );
		add_action( 'shutdown', [ __CLASS__, 'flush_pending' ], 5 ); // before Cache's purge, which may be slow
		add_action( self::RETRY_HOOK, [ __CLASS__, 'drain' ] );
		add_action( 'admin_menu', [ $this, 'menu' ] );
		foreach ( [ 'add', 'del', 'toggle', 'test', 'rotate' ] as $a ) {
			add_action( 'admin_post_gasf_webhooks_' . $a, [ $this, 'action_' . $a ] );
		}
	}

	/* ---- collecting changes ------------------------------------------- */

	public static function on_save( $post_id, $post, $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			self::note( (int) $post_id, 'updated' );
		}
	}

	public static function on_transition( $new, $old, $post ): void {
		if ( ! $post instanceof \WP_Post || GASF_EVENTS_CPT !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'publish' === $new ) {
			self::note( (int) $post->ID, 'updated' );
		} elseif ( 'publish' === $old ) {
			self::note( (int) $post->ID, 'removed' );
		}
	}

	public static function on_delete( $post_id, $post = null ): void {
		$p = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( $p && GASF_EVENTS_CPT === $p->post_type && 'publish' === $p->post_status ) {
			self::note( (int) $post_id, 'removed' );
		}
	}

	/** `removed` wins over `updated` for the same post in one request. */
	private static function note( int $id, string $action ): void {
		if ( 'removed' === $action || ! isset( self::$changes[ $id ] ) ) {
			self::$changes[ $id ] = $action;
		}
	}

	/* ---- delivery ----------------------------------------------------- */

	public static function flush_pending(): void {
		if ( ! self::$changes ) {
			return;
		}
		$changes       = self::$changes;
		self::$changes = [];
		$hooks         = self::enabled_hooks();
		if ( ! $hooks ) {
			return;
		}
		$body = self::body( 'events.changed', self::describe( $changes ) );
		foreach ( $hooks as $hook ) {
			self::send_or_queue( $hook, 'events.changed', $body );
		}
	}

	/**
	 * The change list: `updated` entries carry a compact event (CHANGE_FIELDS)
	 * read now, so the receiver can act without a round trip; `removed`
	 * entries are an id and the status that took the event off the feed.
	 */
	private static function describe( array $changes ): array {
		$rest = new Rest();
		$out  = [];
		foreach ( $changes as $id => $action ) {
			$event = Event::get( $id );
			if ( 'updated' === $action && $event && 'publish' === get_post_status( $id ) ) {
				$out[] = [ 'action' => 'updated' ] + $rest->to_json( $event, self::CHANGE_FIELDS );
			} else {
				$out[] = [ 'action' => 'removed', 'id' => (int) $id, 'post_status' => (string) ( get_post_status( $id ) ?: 'deleted' ) ];
			}
		}
		return $out;
	}

	private static function body( string $type, array $changes ): array {
		return [
			'event'       => $type,
			'sent_at'     => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'changes'     => $changes,
			// Where to re-read everything if a delivery was missed.
			'changes_url' => rest_url( Rest::NS . '/events/changes' ),
			'events_url'  => rest_url( Rest::NS . '/events' ),
		];
	}

	private static function send_or_queue( array $hook, string $type, array $body ): void {
		$delivery = wp_generate_uuid4();
		$res      = self::post( $hook, $type, $delivery, $body );
		self::record( (string) $hook['id'], $res );
		if ( '' !== $res['error'] ) {
			self::enqueue( [ 'hook' => $hook['id'], 'delivery' => $delivery, 'event' => $type, 'body' => $body, 'attempts' => 1, 'next' => time() + 2 * MINUTE_IN_SECONDS ] );
		}
	}

	/**
	 * One signed POST. Returns [ code, error ]; error '' means a 2xx.
	 *
	 * @return array{code:int,error:string}
	 */
	private static function post( array $hook, string $type, string $delivery, array $body ): array {
		$json = (string) wp_json_encode( $body );
		$sig  = hash_hmac( 'sha256', $json, (string) ( $hook['secret'] ?? '' ) );
		$resp = wp_remote_post( (string) $hook['url'], [
			'timeout'            => self::TIMEOUT,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => [
				'Content-Type'     => 'application/json',
				'User-Agent'       => 'GASF-Events/' . GASF_EVENTS_VERSION . ' (+' . home_url( '/' ) . ')',
				'X-GASF-Event'     => $type,
				'X-GASF-Delivery'  => $delivery,
				'X-GASF-Signature' => 'sha256=' . $sig,
			],
			'body'               => $json,
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 'code' => 0, 'error' => $resp->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return [ 'code' => $code, 'error' => ( $code >= 200 && $code < 300 ) ? '' : 'HTTP ' . $code ];
	}

	/* ---- retry queue -------------------------------------------------- */

	private static function enqueue( array $item ): void {
		$q   = (array) get_option( self::QUEUE, [] );
		$q[] = $item;
		update_option( self::QUEUE, array_slice( $q, -200 ), false ); // bounded: a dead receiver cannot grow it forever
		self::schedule_retry( (int) $item['next'] );
	}

	private static function schedule_retry( int $at ): void {
		$next = wp_next_scheduled( self::RETRY_HOOK );
		if ( ! $next || $next > $at ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::RETRY_HOOK );
			}
			wp_schedule_single_event( max( $at, time() + 60 ), self::RETRY_HOOK );
		}
	}

	/** Retry everything that is due; back off what fails; drop what is spent. */
	public static function drain(): void {
		$q     = (array) get_option( self::QUEUE, [] );
		$hooks = [];
		foreach ( self::hooks() as $h ) {
			$hooks[ (string) $h['id'] ] = $h;
		}
		$keep = [];
		$now  = time();
		foreach ( $q as $item ) {
			$hook = $hooks[ (string) ( $item['hook'] ?? '' ) ] ?? null;
			if ( ! $hook || empty( $hook['enabled'] ) ) {
				continue; // hook removed or paused: nothing to deliver to
			}
			if ( (int) ( $item['next'] ?? 0 ) > $now ) {
				$keep[] = $item;
				continue;
			}
			$res = self::post( $hook, (string) $item['event'], (string) $item['delivery'], (array) $item['body'] );
			self::record( (string) $hook['id'], $res );
			if ( '' === $res['error'] ) {
				continue;
			}
			$item['attempts'] = (int) ( $item['attempts'] ?? 1 ) + 1;
			if ( $item['attempts'] >= self::MAX_ATTEMPTS ) {
				continue; // spent — the last error stays on the hook for the screen
			}
			$item['next'] = $now + ( 2 ** $item['attempts'] ) * MINUTE_IN_SECONDS;
			$keep[]       = $item;
		}
		update_option( self::QUEUE, $keep, false );
		if ( $keep ) {
			self::schedule_retry( (int) min( array_column( $keep, 'next' ) ) );
		}
	}

	private static function record( string $id, array $res ): void {
		$hooks = self::hooks();
		foreach ( $hooks as &$h ) {
			if ( (string) $h['id'] === $id ) {
				$h['last_ts']     = time();
				$h['last_status'] = (int) $res['code'];
				$h['last_error']  = (string) $res['error'];
				break;
			}
		}
		unset( $h );
		update_option( self::OPTION, $hooks, false );
	}

	/* ---- config ------------------------------------------------------- */

	public static function hooks(): array {
		$h = get_option( self::OPTION, [] );
		return is_array( $h ) ? array_values( $h ) : [];
	}

	public static function enabled_hooks(): array {
		return array_values( array_filter( self::hooks(), static fn( $h ) => ! empty( $h['enabled'] ) && ! empty( $h['url'] ) ) );
	}

	public static function queue_length(): int {
		return count( (array) get_option( self::QUEUE, [] ) );
	}

	/** Hooks whose last delivery failed, for the Health screen. */
	public static function failing(): array {
		return array_values( array_filter( self::enabled_hooks(), static fn( $h ) => '' !== (string) ( $h['last_error'] ?? '' ) ) );
	}

	/* ---- admin ---------------------------------------------------------- */

	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . GASF_EVENTS_CPT, __( 'Webhooks', 'gasf-events' ), __( 'Webhooks', 'gasf-events' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	private static function url( string $msg = '' ): string {
		$u = admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=' . self::SLUG );
		return '' !== $msg ? add_query_arg( 'gasf_msg', rawurlencode( $msg ), $u ) : $u;
	}

	private function guard(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( 'gasf_webhooks' );
		return sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
	}

	private function back( string $msg ): void {
		wp_safe_redirect( self::url( $msg ) );
		exit;
	}

	public function action_add(): void {
		$this->guard();
		$url = esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			$this->back( __( 'That is not a URL this site can reach. It must be http(s) and publicly resolvable — a receiver on the club LAN is not reachable from the web host.', 'gasf-events' ) );
		}
		$secret  = trim( (string) wp_unslash( $_POST['secret'] ?? '' ) );
		$hooks   = self::hooks();
		$hooks[] = [
			'id'          => 'w' . substr( md5( uniqid( '', true ) ), 0, 8 ),
			'url'         => $url,
			'label'       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'secret'      => '' !== $secret ? $secret : wp_generate_password( 40, false, false ),
			'enabled'     => true,
			'created'     => time(),
			'last_ts'     => 0,
			'last_status' => 0,
			'last_error'  => '',
		];
		update_option( self::OPTION, $hooks, false );
		$this->back( __( 'Webhook added. Copy its secret into the receiver, then press Test.', 'gasf-events' ) );
	}

	public function action_del(): void {
		$id = $this->guard();
		update_option( self::OPTION, array_values( array_filter( self::hooks(), static fn( $h ) => (string) $h['id'] !== $id ) ), false );
		update_option( self::QUEUE, array_values( array_filter( (array) get_option( self::QUEUE, [] ), static fn( $i ) => (string) ( $i['hook'] ?? '' ) !== $id ) ), false );
		$this->back( __( 'Webhook removed.', 'gasf-events' ) );
	}

	public function action_toggle(): void {
		$id    = $this->guard();
		$hooks = self::hooks();
		foreach ( $hooks as &$h ) {
			if ( (string) $h['id'] === $id ) {
				$h['enabled'] = empty( $h['enabled'] );
			}
		}
		unset( $h );
		update_option( self::OPTION, $hooks, false );
		$this->back( __( 'Saved.', 'gasf-events' ) );
	}

	public function action_rotate(): void {
		$id    = $this->guard();
		$hooks = self::hooks();
		foreach ( $hooks as &$h ) {
			if ( (string) $h['id'] === $id ) {
				$h['secret'] = wp_generate_password( 40, false, false );
			}
		}
		unset( $h );
		update_option( self::OPTION, $hooks, false );
		$this->back( __( 'New secret generated — update the receiver before the next delivery.', 'gasf-events' ) );
	}

	/** A `ping` with one sample change, delivered now, so the receiver can be checked end to end. */
	public function action_test(): void {
		$id   = $this->guard();
		$hook = null;
		foreach ( self::hooks() as $h ) {
			if ( (string) $h['id'] === $id ) {
				$hook = $h;
			}
		}
		if ( ! $hook ) {
			$this->back( __( 'Unknown webhook.', 'gasf-events' ) );
		}
		$sample = Calendar::upcoming( 1 );
		$change = $sample ? [ [ 'action' => 'updated' ] + ( new Rest() )->to_json( $sample[0], self::CHANGE_FIELDS ) ] : [];
		$res    = self::post( $hook, 'ping', wp_generate_uuid4(), self::body( 'ping', $change ) );
		self::record( (string) $hook['id'], $res );
		$this->back( '' === $res['error']
			? sprintf( /* translators: %d: HTTP status */ __( 'Ping delivered (HTTP %d).', 'gasf-events' ), $res['code'] )
			: sprintf( /* translators: %s: error text */ __( 'Ping failed: %s', 'gasf-events' ), $res['error'] ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hooks = self::hooks();
		$queue = self::queue_length();
		$msg   = isset( $_GET['gasf_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$u     = esc_url( admin_url( 'admin-post.php' ) );
		$form  = static function ( string $action, string $id, string $label, string $class = 'button-link', string $confirm = '' ) use ( $u ) {
			printf(
				'<form method="post" action="%s" style="display:inline;"%s>%s<input type="hidden" name="action" value="gasf_webhooks_%s"><input type="hidden" name="id" value="%s"><button class="%s">%s</button></form>',
				$u,
				'' !== $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '',
				wp_nonce_field( 'gasf_webhooks', '_wpnonce', true, false ),
				esc_attr( $action ),
				esc_attr( $id ),
				esc_attr( $class ),
				esc_html( $label )
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webhooks', 'gasf-events' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:62em;">
				<?php esc_html_e( 'Each URL here receives one signed POST whenever the calendar changes — an event published, edited, cancelled, unpublished or removed by the feed sync — so the road sign and the kiosk can react at once instead of polling. Failed deliveries retry for about an hour, then give up and show here. Keep polling /events/changes as the safety net.', 'gasf-events' ); ?>
				<a href="https://github.com/GermanTampaBay/GASF-Events/blob/main/docs/REST-API.md#webhooks" target="_blank" rel="noopener"><?php esc_html_e( 'Payload and signature details', 'gasf-events' ); ?></a>
			</p>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Receiver', 'gasf-events' ); ?></th>
					<th style="width:280px;"><?php esc_html_e( 'Secret', 'gasf-events' ); ?></th>
					<th style="width:230px;"><?php esc_html_e( 'Last delivery', 'gasf-events' ); ?></th>
					<th style="width:60px;"><?php esc_html_e( 'On?', 'gasf-events' ); ?></th>
					<th style="width:260px;"></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $hooks ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No webhooks yet.', 'gasf-events' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $hooks as $h ) : ?>
					<tr>
						<td>
							<?php if ( ! empty( $h['label'] ) ) : ?><strong><?php echo esc_html( $h['label'] ); ?></strong><br><?php endif; ?>
							<code style="font-size:11px;"><?php echo esc_html( $h['url'] ); ?></code>
						</td>
						<td><input type="text" readonly class="code" style="width:100%;font-size:11px;" onclick="this.select()" value="<?php echo esc_attr( $h['secret'] ); ?>"></td>
						<td>
							<?php if ( empty( $h['last_ts'] ) ) : ?>
								<span style="color:#888;"><?php esc_html_e( 'never', 'gasf-events' ); ?></span>
							<?php elseif ( '' === (string) ( $h['last_error'] ?? '' ) ) : ?>
								<span style="color:#1a7f37;">● HTTP <?php echo (int) $h['last_status']; ?></span>
								<small style="color:#666;"><?php echo esc_html( human_time_diff( (int) $h['last_ts'] ) ); ?> <?php esc_html_e( 'ago', 'gasf-events' ); ?></small>
							<?php else : ?>
								<span style="color:#b32d2e;">✖ <?php echo esc_html( $h['last_error'] ); ?></span>
								<small style="color:#666;"><?php echo esc_html( human_time_diff( (int) $h['last_ts'] ) ); ?> <?php esc_html_e( 'ago', 'gasf-events' ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo ! empty( $h['enabled'] ) ? '✓' : '—'; ?></td>
						<td style="white-space:nowrap;">
							<?php $form( 'test', (string) $h['id'], __( 'Test', 'gasf-events' ) ); ?> |
							<?php $form( 'toggle', (string) $h['id'], ! empty( $h['enabled'] ) ? __( 'Disable', 'gasf-events' ) : __( 'Enable', 'gasf-events' ) ); ?> |
							<?php $form( 'rotate', (string) $h['id'], __( 'New secret', 'gasf-events' ), 'button-link', __( 'Generate a new secret? The receiver must be updated.', 'gasf-events' ) ); ?> |
							<?php $form( 'del', (string) $h['id'], __( 'Remove', 'gasf-events' ), 'button-link delete', __( 'Remove this webhook?', 'gasf-events' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $queue ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of deliveries */ _n( '%d delivery waiting to retry.', '%d deliveries waiting to retry.', $queue, 'gasf-events' ), $queue ) ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a receiver', 'gasf-events' ); ?></h2>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_webhooks' ); ?>
				<input type="hidden" name="action" value="gasf_webhooks_add">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label (e.g. Road sign)', 'gasf-events' ); ?>" size="20">
				<input type="url" name="url" placeholder="https://…/gasf-webhook" size="48" required>
				<input type="text" name="secret" placeholder="<?php esc_attr_e( 'Secret (blank = generate one)', 'gasf-events' ); ?>" size="30">
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>
		</div>
		<?php
	}
}
