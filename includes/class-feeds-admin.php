<?php
/**
 * "Feeds" admin page — the single home for event ingestion + syndication.
 * Manage source feeds (Facebook pages, ICS calendars), choose destinations per
 * feed (GASF Calendar and/or Google Calendar), set the Google target, run /
 * dry-run, and read status + log. Admin-only and master-gated.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Feeds_Admin {

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_gasf_feeds_gate', [ $this, 'save_gate' ] );
		add_action( 'admin_post_gasf_feeds_add', [ $this, 'add_feed' ] );
		add_action( 'admin_post_gasf_feeds_update', [ $this, 'update_feed' ] );
		add_action( 'admin_post_gasf_feeds_del', [ $this, 'del_feed' ] );
		add_action( 'admin_post_gasf_feeds_toggle', [ $this, 'toggle_feed' ] );
		add_action( 'admin_post_gasf_feeds_import_mec', [ $this, 'import_mec' ] );
		add_action( 'admin_post_gasf_feeds_run', [ $this, 'run' ] );
		add_action( 'admin_post_gasf_feeds_eventbrite', [ $this, 'save_eventbrite' ] );
	}

	public function save_eventbrite(): void {
		$this->guard( 'gasf_feeds_eb' );
		$cur   = Eventbrite::config();
		$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
		// Keep the stored token if the field was left as the masked preview / blank.
		if ( '' === $token || false !== strpos( $token, '…' ) ) {
			$token = (string) $cur['token'];
		}
		update_option( Eventbrite::OPTION, [
			'token'           => $token,
			'organization_id' => sanitize_text_field( wp_unslash( $_POST['organization_id'] ?? '' ) ),
			'enabled'         => ! empty( $_POST['enabled'] ),
		], false );
		$this->back();
	}

	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . GASF_EVENTS_CPT, __( 'Feeds', 'gasf-events' ), __( 'Feeds', 'gasf-events' ), 'manage_options', 'gasf-events-feeds', [ $this, 'render' ] );
	}

	private function url(): string {
		return admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=gasf-events-feeds' );
	}

	private function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( $nonce );
	}

	private function back(): void {
		wp_safe_redirect( $this->url() );
		exit;
	}

	/* ---- handlers ----------------------------------------------------- */

	public function save_gate(): void {
		$this->guard( 'gasf_feeds_gate' );
		update_option( Feeds::OPT_ENABLE, ! empty( $_POST['enable'] ), false );
		update_option( Feeds::OPT_GCAL, [ 'calendar_id' => sanitize_text_field( wp_unslash( $_POST['calendar_id'] ?? '' ) ) ], false );
		( new Feeds() )->sync_cron_state();
		$this->back();
	}

	public function add_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$type  = ( ( $_POST['type'] ?? '' ) === 'ics' ) ? 'ics' : 'facebook';
		$feeds = Feeds::feeds();
		$feed  = [
			'id'          => Feeds::new_id(),
			'type'        => $type,
			'label'       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'enabled'     => ! empty( $_POST['enabled'] ),
			'dest_gasf'   => ! empty( $_POST['dest_gasf'] ),
			'dest_google' => ! empty( $_POST['dest_google'] ),
			'filter'      => sanitize_text_field( wp_unslash( $_POST['filter'] ?? '' ) ),
			'prefix'      => sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) ),
			'gcal_id'     => sanitize_text_field( wp_unslash( $_POST['gcal_id'] ?? '' ) ),
			'gcal_color'  => ( '' !== (string) ( $_POST['gcal_color'] ?? '' ) && (int) $_POST['gcal_color'] >= 1 && (int) $_POST['gcal_color'] <= 11 ) ? (string) (int) $_POST['gcal_color'] : '',
			'dest_ics'    => ! empty( $_POST['dest_ics'] ),
			'link_source' => ! empty( $_POST['link_source'] ),
			'definitive'  => ! empty( $_POST['definitive'] ),
		];
		if ( 'ics' === $type ) {
			$feed['url'] = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		} else {
			$feed['page_id']      = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
			$feed['access_token'] = trim( (string) wp_unslash( $_POST['access_token'] ?? '' ) );
			$feed['expire_at']    = ! empty( $_POST['expire_at'] ) ? (int) strtotime( sanitize_text_field( wp_unslash( $_POST['expire_at'] ) ) ) : 0;
		}
		if ( $feed['dest_ics'] ) {
			$feed['ics_token'] = wp_generate_password( 24, false, false );
		}
		$feeds[] = $feed;
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	/**
	 * Update an existing feed in place. id + type are immutable; every other
	 * field is re-read from the form. A Facebook token/expiry left blank keeps
	 * the stored value, so you can edit the filter without re-entering the token.
	 */
	public function update_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$id    = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$feeds = Feeds::feeds();
		$hit   = false;
		foreach ( $feeds as &$f ) {
			if ( ( $f['id'] ?? '' ) !== $id ) {
				continue;
			}
			$hit = true;
			$f['label']       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
			$f['enabled']     = ! empty( $_POST['enabled'] );
			$f['dest_gasf']   = ! empty( $_POST['dest_gasf'] );
			$f['dest_google'] = ! empty( $_POST['dest_google'] );
			$f['filter']      = sanitize_text_field( wp_unslash( $_POST['filter'] ?? '' ) );
			$f['prefix']      = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? '' ) );
			$f['gcal_id']     = sanitize_text_field( wp_unslash( $_POST['gcal_id'] ?? '' ) );
			$f['gcal_color']  = ( '' !== (string) ( $_POST['gcal_color'] ?? '' ) && (int) $_POST['gcal_color'] >= 1 && (int) $_POST['gcal_color'] <= 11 ) ? (string) (int) $_POST['gcal_color'] : '';
			$f['dest_ics']    = ! empty( $_POST['dest_ics'] );
			$f['link_source'] = ! empty( $_POST['link_source'] );
			$f['definitive']  = ! empty( $_POST['definitive'] );
			if ( $f['dest_ics'] && empty( $f['ics_token'] ) ) { // keep a stable URL once minted
				$f['ics_token'] = wp_generate_password( 24, false, false );
			}
			if ( 'ics' === ( $f['type'] ?? '' ) ) {
				$f['url'] = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
			} else {
				$f['page_id'] = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );
				$tok          = trim( (string) wp_unslash( $_POST['access_token'] ?? '' ) );
				if ( '' !== $tok ) { // blank = keep the stored token
					$f['access_token'] = $tok;
				}
				if ( ! empty( $_POST['expire_at'] ) ) {
					$f['expire_at'] = (int) strtotime( sanitize_text_field( wp_unslash( $_POST['expire_at'] ) ) );
				}
			}
			break;
		}
		unset( $f );
		if ( $hit ) {
			Feeds::save_feeds( $feeds );
		}
		$this->back();
	}

	public function del_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		Feeds::save_feeds( array_filter( Feeds::feeds(), static fn( $f ) => ( $f['id'] ?? '' ) !== $id ) );
		delete_transient( 'gasf_feed_ics_' . $id );  // passthrough .ics cache + last-good
		delete_option( 'gasf_feed_ics_lg_' . $id );
		$this->back();
	}

	public function toggle_feed(): void {
		$this->guard( 'gasf_feeds_edit' );
		$id    = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$feeds = array_map( static function ( $f ) use ( $id ) {
			if ( ( $f['id'] ?? '' ) === $id ) {
				$f['enabled'] = empty( $f['enabled'] );
			}
			return $f;
		}, Feeds::feeds() );
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	/** Import FB token(s) from MEC + ICS sources from the legacy Calendar Sync. */
	public function import_mec(): void {
		$this->guard( 'gasf_feeds_edit' );
		$feeds = Feeds::feeds();

		$mec = get_option( 'mec_advimp_auth_facebook' );
		if ( is_array( $mec ) ) {
			foreach ( $mec as $entry ) {
				if ( empty( $entry['access_token'] ) ) {
					continue;
				}
				$feeds[] = [
					'id' => Feeds::new_id(), 'type' => 'facebook', 'label' => sanitize_text_field( (string) ( $entry['title'] ?? 'Facebook (MEC)' ) ),
					'enabled' => false, 'dest_gasf' => true, 'dest_google' => false,
					'page_id' => 'GermanTampa', 'access_token' => (string) $entry['access_token'], 'expire_at' => (int) ( $entry['expire_at'] ?? 0 ),
				];
			}
		}
		foreach ( (array) get_option( 'gasf_calsync_sources', [] ) as $src ) {
			if ( empty( $src['url'] ) ) {
				continue;
			}
			$feeds[] = [
				'id' => Feeds::new_id(), 'type' => 'ics', 'label' => sanitize_text_field( (string) ( $src['label'] ?? 'ICS' ) ),
				'enabled' => false, 'dest_gasf' => false, 'dest_google' => true, 'url' => esc_url_raw( (string) $src['url'] ),
			];
		}
		Feeds::save_feeds( $feeds );
		$this->back();
	}

	public function run(): void {
		$this->guard( 'gasf_feeds_run' );
		$dry  = ! empty( $_POST['dry'] );
		$only = sanitize_text_field( wp_unslash( $_POST['only'] ?? '' ) );
		$stats = Feeds::run( $dry, $only );
		set_transient( 'gasf_feeds_result_' . get_current_user_id(), $stats, 120 );
		$this->back();
	}

	/* ---- render ------------------------------------------------------- */

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = Feeds::enabled();
		$feeds   = Feeds::feeds();
		$gcal    = Feeds::gcal();
		$last    = (array) get_option( Feeds::OPT_LAST_RUN, [] );
		$log     = (array) get_option( Feeds::OPT_LOG, [] );
		$expiry  = Feeds::soonest_expiry_days();
		$result  = get_transient( 'gasf_feeds_result_' . get_current_user_id() );
		delete_transient( 'gasf_feeds_result_' . get_current_user_id() );
		$u = esc_url( admin_url( 'admin-post.php' ) );

		// Edit mode: ?edit=<feed id> pre-fills an inline editor for that feed.
		$editing = null;
		if ( ! empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$eid = sanitize_text_field( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $feeds as $ef ) {
				if ( ( $ef['id'] ?? '' ) === $eid ) {
					$editing = $ef;
					break;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Feeds', 'gasf-events' ); ?></h1>
			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Sync is OFF.', 'gasf-events' ); ?></strong> <?php esc_html_e( 'No feed runs automatically until you tick “Enable automatic sync” below. Use “Dry run” to preview without writing anything.', 'gasf-events' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $expiry && $expiry < 14 ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %d days */ __( '⚠ A Facebook token expires in %d days.', 'gasf-events' ), $expiry ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $result ) : ?>
				<div class="notice notice-info"><p><strong><?php echo $result['dry'] ? esc_html__( 'Dry run', 'gasf-events' ) : esc_html__( 'Run', 'gasf-events' ); ?>:</strong>
					<?php echo esc_html( sprintf( 'GASF created %d · updated %d · drafted %d · skipped %d  |  Google +%d ~%d -%d', $result['created'], $result['updated'], $result['drafted'], $result['skipped'], $result['google']['inserted'], $result['google']['updated'], $result['google']['deleted'] ) ); ?>
					<?php if ( ! empty( $result['errors'] ) ) : ?><br><span style="color:#b32d2e;"><?php echo esc_html( implode( ' | ', $result['errors'] ) ); ?></span><?php endif; ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Global', 'gasf-events' ); ?></h2>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_gate' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_gate">
				<p><label><input type="checkbox" name="enable" value="1" <?php checked( $enabled ); ?>> <strong><?php esc_html_e( 'Enable automatic sync (every 15 min)', 'gasf-events' ); ?></strong></label></p>
				<p><label><?php esc_html_e( 'Google Calendar ID (default destination — a feed can override with its own):', 'gasf-events' ); ?>
					<input type="text" name="calendar_id" size="48" value="<?php echo esc_attr( $gcal['calendar_id'] ); ?>" placeholder="…@group.calendar.google.com"></label>
					<span style="color:<?php echo Google_Calendar::available() ? '#46810b' : '#b32d2e'; ?>;"><?php echo Google_Calendar::available() ? esc_html__( '✓ key found', 'gasf-events' ) : esc_html__( '✗ service-account key not found', 'gasf-events' ); ?></span>
				</p>
				<?php submit_button( __( 'Save', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'gasf-events' ); ?></h2>
			<table class="widefat striped" style="max-width:680px;"><tbody>
				<tr><th><?php esc_html_e( 'Last run', 'gasf-events' ); ?></th><td><?php echo $last ? esc_html( wp_date( 'M j, Y g:i a', (int) $last['ts'] ) ) : '—'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Next scheduled', 'gasf-events' ); ?></th><td><?php $n = wp_next_scheduled( Feeds::CRON_HOOK ); echo $n ? esc_html( wp_date( 'M j, g:i a', $n ) ) : esc_html__( 'not scheduled (off)', 'gasf-events' ); ?></td></tr>
			</tbody></table>
			<p>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><input type="hidden" name="dry" value="1"><?php submit_button( __( 'Dry run (no writes)', 'gasf-events' ), 'secondary', '', false ); ?></form>
				<form method="post" action="<?php echo $u; ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Run a real sync now?', 'gasf-events' ) ); ?>');"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><?php submit_button( __( 'Sync now', 'gasf-events' ), 'primary', '', false ); ?></form>
			</p>

			<h2><?php esc_html_e( 'Feeds', 'gasf-events' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Label', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Type', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Source', 'gasf-events' ); ?></th><th><?php esc_html_e( 'Destinations', 'gasf-events' ); ?></th><th><?php esc_html_e( 'On?', 'gasf-events' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( ! $feeds ) : ?><tr><td colspan="6"><?php esc_html_e( 'No feeds yet.', 'gasf-events' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $feeds as $f ) :
						$dests = array_filter( [ ! empty( $f['dest_gasf'] ) ? 'Events' : '', ! empty( $f['dest_google'] ) ? 'Google' : '', ! empty( $f['dest_ics'] ) ? '.ics' : '' ] );
						$src   = 'ics' === ( $f['type'] ?? '' ) ? ( $f['url'] ?? '' ) : ( $f['page_id'] ?? '' );
						$is_editing = $editing && ( $editing['id'] ?? '' ) === ( $f['id'] ?? '' );
						?>
						<tr<?php echo $is_editing ? ' style="background:#fcf9e8;"' : ''; ?>>
							<td><?php echo esc_html( $f['label'] ?? '' ); ?><?php
								$mods = array_filter( [
									! empty( $f['filter'] ) ? sprintf( /* translators: %s filter text */ __( 'filter: “%s”', 'gasf-events' ), $f['filter'] ) : '',
									! empty( $f['prefix'] ) ? sprintf( /* translators: %s title prefix */ __( 'prefix: “%s”', 'gasf-events' ), $f['prefix'] ) : '',
								] );
								if ( $mods ) : ?><br><small style="color:#646970;"><?php echo esc_html( implode( ' · ', $mods ) ); ?></small><?php endif; ?></td>
							<td><?php echo esc_html( strtoupper( $f['type'] ?? '' ) ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( mb_strimwidth( (string) $src, 0, 48, '…' ) ); ?></code></td>
							<td><?php echo esc_html( $dests ? implode( ' + ', $dests ) : '—' ); ?><?php if ( ! empty( $f['dest_google'] ) && ! empty( $f['gcal_id'] ) ) : ?><br><small style="color:#646970;"><?php echo esc_html( mb_strimwidth( (string) $f['gcal_id'], 0, 28, '…' ) ); ?></small><?php endif; ?><?php $sub = Feeds::ics_subscribe_url( $f ); if ( $sub ) : ?><br><a href="<?php echo esc_url( $sub ); ?>" target="_blank" rel="noopener" style="font-size:11px;">.ics&nbsp;link&nbsp;&#8599;</a><?php endif; ?></td>
							<td><?php echo ! empty( $f['enabled'] ) ? '✓' : '—'; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'edit', $f['id'], $this->url() ) ); ?>"><?php esc_html_e( 'Edit', 'gasf-events' ); ?></a> |
								<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_edit' ); ?><input type="hidden" name="action" value="gasf_feeds_toggle"><input type="hidden" name="id" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link"><?php echo ! empty( $f['enabled'] ) ? esc_html__( 'Disable', 'gasf-events' ) : esc_html__( 'Enable', 'gasf-events' ); ?></button></form> |
								<form method="post" action="<?php echo $u; ?>" style="display:inline;"><?php wp_nonce_field( 'gasf_feeds_run' ); ?><input type="hidden" name="action" value="gasf_feeds_run"><input type="hidden" name="dry" value="1"><input type="hidden" name="only" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link"><?php esc_html_e( 'Test', 'gasf-events' ); ?></button></form> |
								<form method="post" action="<?php echo $u; ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Remove feed?', 'gasf-events' ) ); ?>');"><?php wp_nonce_field( 'gasf_feeds_edit' ); ?><input type="hidden" name="action" value="gasf_feeds_del"><input type="hidden" name="id" value="<?php echo esc_attr( $f['id'] ); ?>"><button class="button-link delete"><?php esc_html_e( 'Remove', 'gasf-events' ); ?></button></form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $editing ) :
				$is_ics = 'ics' === ( $editing['type'] ?? '' ); ?>
			<div style="border:1px solid #2271b1;background:#f0f6fc;border-radius:3px;padding:12px 16px;margin:14px 0;max-width:900px;">
				<h3 style="margin-top:0;"><?php echo esc_html( sprintf( /* translators: %s feed label */ __( 'Edit feed: %s', 'gasf-events' ), $editing['label'] ?? '' ) ); ?> <span style="font-weight:400;color:#646970;">(<?php echo esc_html( strtoupper( (string) ( $editing['type'] ?? '' ) ) ); ?>)</span></h3>
				<form method="post" action="<?php echo $u; ?>">
					<?php wp_nonce_field( 'gasf_feeds_edit' ); ?>
					<input type="hidden" name="action" value="gasf_feeds_update">
					<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
					<p><label><?php esc_html_e( 'Label', 'gasf-events' ); ?><br>
						<input type="text" name="label" value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>" size="34" required></label></p>
					<?php if ( $is_ics ) : ?>
						<p><label><?php esc_html_e( 'iCal (.ics) URL', 'gasf-events' ); ?><br>
							<input type="url" name="url" value="<?php echo esc_attr( $editing['url'] ?? '' ); ?>" size="60" required></label></p>
					<?php else : ?>
						<p><label><?php esc_html_e( 'Page id / username', 'gasf-events' ); ?><br>
							<input type="text" name="page_id" value="<?php echo esc_attr( $editing['page_id'] ?? '' ); ?>" size="34" required></label></p>
						<p><label><?php esc_html_e( 'Access token', 'gasf-events' ); ?><br>
							<input type="text" name="access_token" value="" size="44" placeholder="<?php esc_attr_e( 'leave blank to keep the saved token', 'gasf-events' ); ?>"></label></p>
						<p><label><?php esc_html_e( 'Token expiry', 'gasf-events' ); ?>
							<input type="date" name="expire_at" value="<?php echo esc_attr( ! empty( $editing['expire_at'] ) ? wp_date( 'Y-m-d', (int) $editing['expire_at'] ) : '' ); ?>"></label></p>
					<?php endif; ?>
					<p>
						<label><?php esc_html_e( 'Filter — only titles containing', 'gasf-events' ); ?>
							<input type="text" name="filter" value="<?php echo esc_attr( $editing['filter'] ?? '' ); ?>" size="24"></label>
						<label style="margin-left:14px;"><?php esc_html_e( 'Title prefix', 'gasf-events' ); ?>
							<input type="text" name="prefix" value="<?php echo esc_attr( $editing['prefix'] ?? '' ); ?>" size="16" placeholder="<?php esc_attr_e( '[TAG]; - = none', 'gasf-events' ); ?>"></label>
					</p>
					<p>
						<label><?php esc_html_e( 'Google Cal ID override', 'gasf-events' ); ?>
							<input type="text" name="gcal_id" value="<?php echo esc_attr( $editing['gcal_id'] ?? '' ); ?>" size="28"></label>
						<label style="margin-left:14px;"><?php esc_html_e( 'Color 1–11', 'gasf-events' ); ?>
							<input type="number" name="gcal_color" min="1" max="11" value="<?php echo esc_attr( $editing['gcal_color'] ?? '' ); ?>" style="width:74px"></label>
					</p>
					<p>
						<label><input type="checkbox" name="dest_gasf" value="1" <?php checked( ! empty( $editing['dest_gasf'] ) ); ?>> <?php esc_html_e( 'Events calendar', 'gasf-events' ); ?></label>
						<label style="margin-left:14px;"><input type="checkbox" name="dest_google" value="1" <?php checked( ! empty( $editing['dest_google'] ) ); ?>> <?php esc_html_e( 'Google Calendar', 'gasf-events' ); ?></label>
						<label style="margin-left:14px;"><input type="checkbox" name="dest_ics" value="1" <?php checked( ! empty( $editing['dest_ics'] ) ); ?>> <?php esc_html_e( '.ics feed', 'gasf-events' ); ?></label>
						<label style="margin-left:14px;"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $editing['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled (auto-sync)', 'gasf-events' ); ?></label>
					</p>
					<p style="margin:0;">
						<label><input type="checkbox" name="link_source" value="1" <?php checked( ! empty( $editing['link_source'] ) ); ?>> <?php esc_html_e( 'Link synced events back to their source (a “View original” button)', 'gasf-events' ); ?></label>
						<br>
						<label title="<?php esc_attr_e( 'When several feeds write to one Google Calendar and carry the same event, the definitive feed keeps its title and times and absorbs the others’ descriptions. Matching needs a close start AND end time AND a similar title.', 'gasf-events' ); ?>"><input type="checkbox" name="definitive" value="1" <?php checked( ! empty( $editing['definitive'] ) ); ?>> <?php esc_html_e( 'Definitive — duplicates from other feeds merge into this one', 'gasf-events' ); ?></label>
						<span class="description"><?php esc_html_e( 'Off = native: the event stands on its own local page with no link out.', 'gasf-events' ); ?></span>
					</p>
					<?php $sub = Feeds::ics_subscribe_url( $editing ); if ( $sub ) : ?>
						<p style="margin:0 0 4px;"><label><?php esc_html_e( 'Subscribable .ics URL (works even when auto-sync is off):', 'gasf-events' ); ?><br>
							<input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $sub ); ?>"></label></p>
					<?php elseif ( ! empty( $editing['dest_ics'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Save to mint the .ics URL.', 'gasf-events' ); ?></p>
					<?php endif; ?>
					<?php submit_button( __( 'Save changes', 'gasf-events' ), 'primary', '', false ); ?>
					<a href="<?php echo esc_url( $this->url() ); ?>" class="button" style="margin-left:6px;"><?php esc_html_e( 'Cancel', 'gasf-events' ); ?></a>
				</form>
			</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Add a Facebook page', 'gasf-events' ); ?></h3>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_edit' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_add"><input type="hidden" name="type" value="facebook">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label', 'gasf-events' ); ?>" required>
				<input type="text" name="page_id" placeholder="<?php esc_attr_e( 'Page id / username', 'gasf-events' ); ?>" value="GermanTampa" required>
				<input type="text" name="access_token" placeholder="<?php esc_attr_e( 'Page access token', 'gasf-events' ); ?>" size="32" required>
				<input type="date" name="expire_at" title="<?php esc_attr_e( 'Token expiry', 'gasf-events' ); ?>">
				<input type="text" name="filter" placeholder="<?php esc_attr_e( 'Only titles containing… (optional)', 'gasf-events' ); ?>" size="24">
				<input type="text" name="prefix" placeholder="<?php esc_attr_e( 'Title prefix, e.g. [GTB] (optional; - = none)', 'gasf-events' ); ?>" size="20">
				<input type="text" name="gcal_id" placeholder="<?php esc_attr_e( 'Google Cal ID override (optional)', 'gasf-events' ); ?>" size="24">
				<input type="number" name="gcal_color" min="1" max="11" placeholder="<?php esc_attr_e( 'Color 1–11', 'gasf-events' ); ?>" style="width:80px" title="<?php esc_attr_e( 'Google event colorId (optional)', 'gasf-events' ); ?>">
				<label><input type="checkbox" name="dest_gasf" value="1" checked> <?php esc_html_e( 'Events calendar', 'gasf-events' ); ?></label>
				<label><input type="checkbox" name="dest_google" value="1"> <?php esc_html_e( 'Google', 'gasf-events' ); ?></label>
				<label title="<?php esc_attr_e( 'Re-expose this feed as its own subscribable .ics URL (a secret link is minted).', 'gasf-events' ); ?>"><input type="checkbox" name="dest_ics" value="1"> <?php esc_html_e( '.ics feed', 'gasf-events' ); ?></label>
				<label title="<?php esc_attr_e( 'Off (default): synced events are native — no link back to the source. On: add a “View original” button to the source event.', 'gasf-events' ); ?>"><input type="checkbox" name="link_source" value="1"> <?php esc_html_e( 'link to source', 'gasf-events' ); ?></label>
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<h3><?php esc_html_e( 'Add an iCal (.ics) feed', 'gasf-events' ); ?></h3>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_edit' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_add"><input type="hidden" name="type" value="ics">
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'Label', 'gasf-events' ); ?>" required>
				<input type="url" name="url" placeholder="https://…/calendar.ics" size="40" required>
				<input type="text" name="filter" placeholder="<?php esc_attr_e( 'Only titles containing… (optional)', 'gasf-events' ); ?>" size="24">
				<input type="text" name="prefix" placeholder="<?php esc_attr_e( 'Title prefix, e.g. [GTB] (optional; - = none)', 'gasf-events' ); ?>" size="20">
				<input type="text" name="gcal_id" placeholder="<?php esc_attr_e( 'Google Cal ID override (optional)', 'gasf-events' ); ?>" size="24">
				<input type="number" name="gcal_color" min="1" max="11" placeholder="<?php esc_attr_e( 'Color 1–11', 'gasf-events' ); ?>" style="width:80px" title="<?php esc_attr_e( 'Google event colorId (optional)', 'gasf-events' ); ?>">
				<label><input type="checkbox" name="dest_gasf" value="1" checked> <?php esc_html_e( 'Events calendar', 'gasf-events' ); ?></label>
				<label><input type="checkbox" name="dest_google" value="1"> <?php esc_html_e( 'Google', 'gasf-events' ); ?></label>
				<label title="<?php esc_attr_e( 'Re-expose this feed as its own subscribable .ics URL (a secret link is minted).', 'gasf-events' ); ?>"><input type="checkbox" name="dest_ics" value="1"> <?php esc_html_e( '.ics feed', 'gasf-events' ); ?></label>
				<label title="<?php esc_attr_e( 'Off (default): synced events are native — no link back to the source. On: add a “View original” button to the source event.', 'gasf-events' ); ?>"><input type="checkbox" name="link_source" value="1"> <?php esc_html_e( 'link to source', 'gasf-events' ); ?></label>
				<?php submit_button( __( 'Add', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Publish events OUT (Eventbrite)', 'gasf-events' ); ?></h2>
			<p class="description"><?php esc_html_e( 'This is the opposite direction from the feeds above. Feeds bring events IN (to the Events calendar / Google). This sends selected events OUT to Eventbrite: set the token once here, then use the “Publish to Eventbrite” bulk action on the All Events screen. Leave it disabled if you don’t publish to Eventbrite from this site.', 'gasf-events' ); ?></p>
			<?php $eb = Eventbrite::config(); ?>
			<form method="post" action="<?php echo $u; ?>">
				<?php wp_nonce_field( 'gasf_feeds_eb' ); ?>
				<input type="hidden" name="action" value="gasf_feeds_eventbrite">
				<h3 style="margin-bottom:4px;">Eventbrite</h3>
				<p>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $eb['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled', 'gasf-events' ); ?></label><br>
					<input type="text" name="organization_id" value="<?php echo esc_attr( $eb['organization_id'] ); ?>" placeholder="<?php esc_attr_e( 'Organization ID', 'gasf-events' ); ?>"><br>
					<input type="text" name="token" value="<?php echo esc_attr( $eb['token'] ? substr( $eb['token'], 0, 4 ) . '…' : '' ); ?>" placeholder="<?php esc_attr_e( 'Private token (leave masked value to keep)', 'gasf-events' ); ?>" size="40">
				</p>
				<?php submit_button( __( 'Save Eventbrite', 'gasf-events' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Recent runs', 'gasf-events' ); ?></h2>
				<textarea readonly rows="8" style="width:100%;max-width:900px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( implode( "\n", $log ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}
}
