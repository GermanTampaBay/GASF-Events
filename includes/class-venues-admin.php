<?php
/**
 * Events → Venues: edit and remove the remembered venues.
 *
 * The list fills itself as events are saved, so this screen exists only for the
 * things that go wrong — a typo that is now in the dropdown forever, a venue
 * that closed, an address that was wrong the first time it was typed.
 *
 * EDITING A VENUE DOES NOT TOUCH ANY EVENT. The list is a remembered form fill,
 * nothing more; each event stores its own copy of the address it was saved with.
 * Fixing a spelling here changes what the dropdown offers next time, not what
 * last year's events say.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Venues_Admin {

	const SLUG  = 'gasf-events-venues';
	const NONCE = 'gasf_venues_save';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_gasf_venues_save', [ $this, 'save' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . GASF_EVENTS_CPT,
			__( 'Venues', 'gasf-events' ),
			__( 'Venues', 'gasf-events' ),
			'edit_gasf_events',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	private function url( string $notice = '' ): string {
		$url = admin_url( 'edit.php?post_type=' . GASF_EVENTS_CPT . '&page=' . self::SLUG );
		return $notice ? add_query_arg( 'gasf_msg', rawurlencode( $notice ), $url ) : $url;
	}

	/**
	 * Rebuild the whole list from what was submitted.
	 *
	 * Rebuilt rather than patched key-by-key because renaming is the main reason
	 * to be on this screen, and a rename changes the fingerprint — patching by
	 * the old key would leave the original behind as a duplicate.
	 */
	public function save(): void {
		if ( ! current_user_can( 'edit_gasf_events' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gasf-events' ) );
		}
		check_admin_referer( self::NONCE );

		$rows   = (array) ( $_POST['venue'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$remove = array_map( 'strval', (array) ( $_POST['remove'] ?? [] ) ); // phpcs:ignore

		$rebuilt = self::rebuild( $rows, $remove );
		update_option( Venues::OPTION, $rebuilt, false );

		wp_safe_redirect( $this->url( sprintf(
			/* translators: %d: number of venues kept */
			__( 'Saved. %d venues remembered.', 'gasf-events' ),
			count( $rebuilt )
		) ) );
		exit;
	}

	/**
	 * The submitted rows as a fresh venue list.
	 *
	 * Separate from save() so it is testable without a request: this is where
	 * renames, merges and removals actually happen.
	 *
	 * @param array    $rows   submitted venue rows, keyed by their OLD fingerprint
	 * @param string[] $remove fingerprints ticked for removal
	 */
	public static function rebuild( array $rows, array $remove = [] ): array {
		$out = [];
		foreach ( $rows as $key => $row ) {
			if ( in_array( (string) $key, $remove, true ) ) {
				continue;
			}
			$clean = Venues::clean( array_map( 'sanitize_text_field', wp_unslash( (array) $row ) ) );
			if ( '' === $clean['name'] ) {
				continue; // a nameless venue is nothing to offer
			}
			// Re-fingerprinted from the EDITED name, so a rename moves cleanly
			// instead of leaving the original behind, and two rows renamed to the
			// same thing merge rather than collide.
			$out[ Venues::fingerprint( $clean ) ] = $clean;
		}
		return $out;
	}

	public function render(): void {
		$venues = Venues::all();
		$msg    = isset( $_GET['gasf_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Venues', 'gasf-events' ); ?></h1>

			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:48em;">
				<?php esc_html_e( 'These are offered in the “Reuse a saved venue” dropdown when you set a venue override on an event. The list fills itself as you save events — edit here only to fix a typo or drop somewhere you no longer use.', 'gasf-events' ); ?>
				<br>
				<strong><?php esc_html_e( 'Editing a venue here does not change any existing event.', 'gasf-events' ); ?></strong>
				<?php esc_html_e( 'Each event keeps its own copy of the address it was saved with.', 'gasf-events' ); ?>
			</p>

			<?php if ( ! $venues ) : ?>
				<p><em><?php esc_html_e( 'No venues remembered yet. Set a venue override on an event and it will appear here.', 'gasf-events' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gasf_venues_save">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:22%;"><?php esc_html_e( 'Name', 'gasf-events' ); ?></th>
							<th style="width:26%;"><?php esc_html_e( 'Street', 'gasf-events' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'City', 'gasf-events' ); ?></th>
							<th style="width:6%;"><?php esc_html_e( 'State', 'gasf-events' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'ZIP', 'gasf-events' ); ?></th>
							<th style="width:8%;"><?php esc_html_e( 'Remove', 'gasf-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $venues as $fp => $v ) : ?>
						<tr>
							<td><input type="text" style="width:100%;" name="venue[<?php echo esc_attr( $fp ); ?>][name]" value="<?php echo esc_attr( $v['name'] ); ?>"></td>
							<td><input type="text" style="width:100%;" name="venue[<?php echo esc_attr( $fp ); ?>][street]" value="<?php echo esc_attr( $v['street'] ); ?>"></td>
							<td><input type="text" style="width:100%;" name="venue[<?php echo esc_attr( $fp ); ?>][city]" value="<?php echo esc_attr( $v['city'] ); ?>"></td>
							<td><input type="text" style="width:100%;" name="venue[<?php echo esc_attr( $fp ); ?>][state]" value="<?php echo esc_attr( $v['state'] ); ?>"></td>
							<td><input type="text" style="width:100%;" name="venue[<?php echo esc_attr( $fp ); ?>][zip]" value="<?php echo esc_attr( $v['zip'] ); ?>"></td>
							<td style="text-align:center;"><input type="checkbox" name="remove[]" value="<?php echo esc_attr( $fp ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save venues', 'gasf-events' ); ?></button>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Clearing a name also removes that venue.', 'gasf-events' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}
}
