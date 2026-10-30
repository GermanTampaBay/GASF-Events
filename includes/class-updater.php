<?php
/**
 * GitHub-based plugin updates (WP 5.8+ `Update URI` mechanism).
 *
 * The repo is public, so no credentials are needed. Since v0.38.0 updates come
 * from the latest GitHub RELEASE (see channel()): bump the Version header in
 * gasf-events.php and merge to main; CI runs the tests and, only if they pass,
 * tags v<version> and publishes a release with a ready-made gasf-events.zip.
 * WordPress's normal update + auto-update UI then offers it. A version that
 * fails its tests is never released, so it never reaches the site.
 * The pre-v0.38.0 behaviour (follow the head of main) is still available as
 * the 'main' channel for a staging copy.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Updater {

	// Moved from the flinchbot personal account to the GermanTampaBay
	// organisation 2026-07-27. GitHub redirects the old URLs for now, but an
	// updater that fails silently (the version check stores '0' on any fetch
	// failure) should not lean on a redirect chain forever.
	const REPO      = 'GermanTampaBay/GASF-Events';
	const RAW_MAIN  = 'https://raw.githubusercontent.com/GermanTampaBay/GASF-Events/main/gasf-events.php';
	const PACKAGE   = 'https://github.com/GermanTampaBay/GASF-Events/archive/refs/heads/main.zip';
	const LATEST    = 'https://api.github.com/repos/GermanTampaBay/GASF-Events/releases/latest';
	const ASSET     = 'gasf-events.zip'; // built by .github/workflows/ci.yml, folder already named GASF-Events/
	const CACHE_KEY = 'gasf_events_update_check';         // remote version string (Health reads it)
	const PKG_KEY   = 'gasf_events_update_package';       // remote package URL

	/**
	 * Where updates come from (v0.38.0).
	 *
	 * 'release' (default): the latest GitHub RELEASE. CI creates one only after
	 * the tests pass on main, so a broken commit on main can no longer reach
	 * the live site within fifteen minutes the way it could before.
	 *
	 * 'main': the head of the main branch, the pre-v0.38.0 behaviour. For a
	 * staging copy that should see every push: in wp-config.php,
	 *   define( 'GASF_EVENTS_UPDATE_CHANNEL', 'main' );
	 */
	public static function channel(): string {
		return ( defined( 'GASF_EVENTS_UPDATE_CHANNEL' ) && 'main' === GASF_EVENTS_UPDATE_CHANNEL ) ? 'main' : 'release';
	}

	private string $file; // absolute path to gasf-events.php

	public function __construct( string $file ) {
		$this->file = $file;
	}

	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder' ], 10, 4 );
		add_filter( 'upgrader_pre_install', [ $this, 'lock_and_backup' ], 10, 2 );
		add_filter( 'upgrader_install_package_result', [ $this, 'verify_or_restore' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* 2026-07-24 incident hardening.                                      */
	/*                                                                     */
	/* Two concurrent updates of this plugin (WP takes NO lock for manual/ */
	/* CLI updates) interleaved clear_destination/copy_dir and left the    */
	/* plugin folder EMPTY while both runs reported success. fix_folder's  */
	/* fail-closed guard can't catch that: it protects the staging step,   */
	/* not the destination step. Two new layers:                           */
	/*   1. lock_and_backup  — serialize via a WP_Upgrader lock (a second  */
	/*      concurrent run aborts BEFORE the destination is cleared) and   */
	/*      snapshot the installed plugin.                                 */
	/*   2. verify_or_restore — after install, if the result is an error   */
	/*      or gasf-events.php is missing from the plugin dir, restore the */
	/*      snapshot. Any failure mode heals back to the prior version.    */
	/* ------------------------------------------------------------------ */

	private function is_ours( $hook_extra ): bool {
		return ! empty( $hook_extra['plugin'] ) && plugin_basename( $this->file ) === $hook_extra['plugin'];
	}

	private function backup_dir(): string {
		return WP_CONTENT_DIR . '/upgrade/gasf-events-preupdate';
	}

	/** Plain-PHP recursive copy — deliberately independent of WP_Filesystem. */
	private static function copy_tree( string $from, string $to ): bool {
		if ( ! is_dir( $from ) ) {
			return false;
		}
		if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
			return false;
		}
		$ok = true;
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		) as $item ) {
			$dest = $to . '/' . substr( (string) $item, strlen( $from ) + 1 );
			if ( $item->isDir() ) {
				$ok = ( is_dir( $dest ) || mkdir( $dest, 0755, true ) ) && $ok;
			} else {
				$ok = copy( (string) $item, $dest ) && $ok;
			}
		}
		return $ok;
	}

	private static function delete_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		) as $item ) {
			$item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $dir );
	}

	/** Abort a second concurrent run before it can clear the destination; snapshot the install. */
	public function lock_and_backup( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! $this->is_ours( (array) $hook_extra ) ) {
			return $response;
		}
		if ( class_exists( '\WP_Upgrader' ) && ! \WP_Upgrader::create_lock( 'gasf_events_upgrade', 2 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'gasf_locked', 'Another gasf-events update is already running; skipped to avoid a destructive race.' );
		}
		$installed = dirname( $this->file );
		$backup    = $this->backup_dir();
		self::delete_tree( $backup );
		if ( is_dir( $installed ) && file_exists( $this->file ) ) {
			self::copy_tree( $installed, $backup );
		}
		return $response;
	}

	/** After install: verify the plugin dir is intact; restore the snapshot if not. */
	public function verify_or_restore( $result, $hook_extra ) {
		if ( ! $this->is_ours( (array) $hook_extra ) ) {
			return $result;
		}
		// The asset cache-buster is a day-cached read of this file's Version
		// header, so an update must drop it or the newly installed CSS/JS keeps
		// being served under the previous version's URL — cached, and therefore
		// invisible, for up to a day after a release that was meant to fix it.
		delete_transient( 'gasf_events_asset_ver' );
		$installed = dirname( $this->file );
		$backup    = $this->backup_dir();
		$broken    = is_wp_error( $result ) || ! file_exists( trailingslashit( $installed ) . basename( $this->file ) );
		if ( $broken && is_dir( $backup ) && file_exists( trailingslashit( $backup ) . basename( $this->file ) ) ) {
			self::copy_tree( $backup, $installed );
			error_log( 'gasf-events updater: install left the plugin broken (' // phpcs:ignore
				. ( is_wp_error( $result ) ? $result->get_error_code() : 'main file missing' )
				. ') — restored the pre-update snapshot.' );
		}
		self::delete_tree( $backup );
		if ( class_exists( '\WP_Upgrader' ) ) {
			\WP_Upgrader::release_lock( 'gasf_events_upgrade' );
		}
		return $result;
	}

	/** Feed WP's update system when it asks about plugins with a github.com Update URI. */
	public function check( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( $this->file ) !== $plugin_file ) {
			return $update;
		}
		[ $remote, $package ] = 'main' === self::channel()
			? [ $this->remote_version(), self::PACKAGE ]
			: $this->latest_release();
		if ( ! $remote || ! $package || version_compare( $remote, (string) $plugin_data['Version'], '<=' ) ) {
			return $update; // up to date (or check failed — never downgrade/loop)
		}
		return [
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => dirname( $plugin_file ),
			'plugin'  => $plugin_file,
			'version' => $remote,
			'url'     => 'https://github.com/' . self::REPO . ( 'main' === self::channel() ? '' : '/releases/tag/v' . $remote ),
			'package' => $package,
		];
	}

	/**
	 * Latest GitHub release: [ version, package URL ], or [ '', '' ] when it
	 * cannot be read (no release yet, network down, rate-limited). A failed
	 * read offers no update rather than a wrong one, and is retried after the
	 * same 15 minutes. The unauthenticated API allows 60 calls an hour per IP;
	 * this makes at most 4.
	 *
	 * The package is the release's own gasf-events.zip when present (built by
	 * CI with the right folder name and without tests/ or .github/), else
	 * GitHub's source zipball, which fix_folder() renames.
	 *
	 * @return array{0:string,1:string}
	 */
	private function latest_release(): array {
		$v = get_transient( self::CACHE_KEY );
		$p = get_transient( self::PKG_KEY );
		if ( is_string( $v ) && is_string( $p ) ) {
			return [ $v, $p ];
		}
		$ver  = '';
		$pkg  = '';
		$resp = wp_remote_get( self::LATEST, [
			'timeout' => 15,
			'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'GASF-Events-updater' ],
		] );
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$rel = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			$tag = is_array( $rel ) ? ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' ) : '';
			if ( preg_match( '/^[0-9][0-9a-z.\-]*$/i', $tag ) && empty( $rel['draft'] ) && empty( $rel['prerelease'] ) ) {
				$ver = $tag;
				foreach ( (array) ( $rel['assets'] ?? [] ) as $a ) {
					if ( self::ASSET === ( $a['name'] ?? '' ) && ! empty( $a['browser_download_url'] ) ) {
						$pkg = (string) $a['browser_download_url'];
					}
				}
				if ( '' === $pkg ) {
					$pkg = (string) ( $rel['zipball_url'] ?? '' );
				}
			}
		}
		set_transient( self::CACHE_KEY, $ver, 15 * MINUTE_IN_SECONDS );
		set_transient( self::PKG_KEY, $pkg, 15 * MINUTE_IN_SECONDS );
		return [ $ver, $pkg ];
	}

	/** Latest Version: header on the main branch (cached 15 min — was 6 h,
	 *  which made every fresh push invisible to `wp plugin update` for hours). */
	private function remote_version(): string {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$resp = wp_remote_get( self::RAW_MAIN, [ 'timeout' => 15 ] );
		$ver  = '';
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			if ( preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9a-z.\-]*)/mi', (string) wp_remote_retrieve_body( $resp ), $m ) ) {
				$ver = trim( $m[1] );
			}
		}
		set_transient( self::CACHE_KEY, $ver, 15 * MINUTE_IN_SECONDS );
		return $ver;
	}

	/**
	 * GitHub zipballs extract as GASF-Events-main/ — rename to the installed
	 * folder (GASF-Events/) so WordPress installs over the right plugin.
	 *
	 * Hardened to fail CLOSED: if it can't produce a correctly-named folder that
	 * actually contains the main plugin file, it returns a WP_Error. That aborts
	 * the upgrade in install_package() *before* WordPress deletes the currently
	 * installed version — so a flaky rename can never leave an empty, still-
	 * active plugin folder (the intermittent outage we kept hitting on Krampus).
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || plugin_basename( $this->file ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source; // no filesystem to act on — leave WP's default behavior
		}
		$main = basename( plugin_basename( $this->file ) );                                    // gasf-events.php
		$want = trailingslashit( $remote_source ) . dirname( plugin_basename( $this->file ) ) . '/'; // …/GASF-Events/

		// Already correctly named: only accept it if it's a real package.
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $wp_filesystem->exists( $want . $main )
				? $source
				: new \WP_Error( 'gasf_pkg_incomplete', sprintf( 'Update package is missing %s; keeping the installed version.', $main ) );
		}

		// Clear any stale target from a prior failed run, then overwrite-move so
		// move() can't early-return false on an existing destination.
		if ( $wp_filesystem->exists( $want ) ) {
			$wp_filesystem->delete( $want, true );
		}
		$moved = $wp_filesystem->move( $source, $want, true );

		// Verify before handing it back — never return a source that would copy
		// nothing into the plugin folder.
		if ( $moved && $wp_filesystem->exists( $want . $main ) ) {
			return $want;
		}
		return new \WP_Error( 'gasf_pkg_move', 'Could not prepare the update package (folder rename failed); keeping the installed version.' );
	}
}
