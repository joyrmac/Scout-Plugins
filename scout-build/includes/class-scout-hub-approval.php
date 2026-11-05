<?php
/**
 * Approval hold: a live site installs a Scout release only after Scout says OK
 * (Joy, 2026-11-04: "all websites update to the staging first before pushing
 * live ... we can push live when approved by client").
 *
 * How a release reaches a client's live site:
 *   1. It installs on the staging copy by itself (staging never waits here).
 *   2. Scout and the client look at it on staging.
 *   3. On the live site, Scout tools, Updates, "Waiting for your OK": Scout
 *      presses Approve, with a note of who said yes. The Site log records it.
 *   4. The live site installs it on its next update run, or at once with Update now.
 *
 * What it holds: automatic updates of Scout plugins and of a theme that updates
 * from GitHub. Update now always works, as before. Security fixes skip the wait:
 * a "security" entry in catalog.json, { slug, version, note }, lets a site that
 * is still below that version install it (or anything newer) as soon as it is out.
 *
 * On by default on a live site, off on staging and on a copy on someone's own
 * computer. Operators can switch it off for one site on the Updates screen;
 * define( 'SCOUT_HUB_APPROVAL', false ) in wp-config.php does the same.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Approval {

	const OPTION_MODE = 'scout_hub_approval';     // '' (by address), 'on' or 'off'.
	const OPTION_OK   = 'scout_hub_approved';     // key => { version, by, note, at }.
	const ACTION      = 'scout_hub_approval';
	const THEME       = 'theme:';                  // Key prefix for a theme, then its folder name.

	public static function boot(): void {
		// Before the rings (30), so a theme update is held here; plugins are held through Scout_Hub_Rings::wait_reason().
		add_filter( 'auto_update_theme', array( __CLASS__, 'filter_theme' ), 25, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_filter( 'scout_hub_inbox', array( __CLASS__, 'inbox_row' ) );
	}

	/** True when releases wait for Scout's OK on this site. */
	public static function on(): bool {
		if ( defined( 'SCOUT_HUB_APPROVAL' ) ) {
			return (bool) SCOUT_HUB_APPROVAL;
		}
		$mode = (string) get_option( self::OPTION_MODE, '' );
		if ( 'on' === $mode || 'off' === $mode ) {
			return 'on' === $mode;
		}
		return self::by_address();
	}

	/** The default for this address: on for a live site, off for staging and local copies. */
	public static function by_address(): bool {
		return Scout_Hub_Health::is_live();
	}

	/** Every approval on this site, keyed by plugin slug or "theme:<folder>". */
	public static function approvals(): array {
		$all = get_option( self::OPTION_OK, array() );
		return is_array( $all ) ? $all : array();
	}

	/** True when Scout has said OK to this version (an OK covers every version up to the one approved). */
	public static function approved( string $key, string $version ): bool {
		$row = self::approvals()[ $key ] ?? null;
		return is_array( $row ) && '' !== (string) ( $row['version'] ?? '' ) && version_compare( $version, (string) $row['version'], '<=' );
	}

	/**
	 * Security fixes from catalog.json: "security": [ { slug, version, note } ].
	 * A theme is listed by its folder name.
	 *
	 * @return array<int, array{slug:string, version:string, note:string}>
	 */
	public static function security(): array {
		Scout_Hub_Catalog::features(); // Fetches and caches the catalog when it is not cached yet.
		$data = get_transient( Scout_Hub_Catalog::CATALOG_KEY );
		$out  = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['security'] ?? array() ) : array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			$ver  = (string) ( $row['version'] ?? '' );
			if ( '' !== $slug && preg_match( '/^\d[\w.\-]*$/', $ver ) ) {
				$out[] = array( 'slug' => $slug, 'version' => $ver, 'note' => sanitize_text_field( (string) ( $row['note'] ?? '' ) ) );
			}
		}
		return $out;
	}

	/**
	 * True when installing this version brings in a security fix: catalog.json marks a
	 * version at or below it, and the site still runs a version below that fix. Once a
	 * site has the fix, later releases wait for an OK as usual.
	 */
	public static function is_security( string $key, string $version ): bool {
		$slug      = 0 === strpos( $key, self::THEME ) ? substr( $key, strlen( self::THEME ) ) : $key;
		$installed = self::installed( $key );
		foreach ( self::security() as $fix ) {
			if ( $fix['slug'] !== $slug || version_compare( $version, $fix['version'], '<' ) ) {
				continue;
			}
			// Unknown installed version: only the exact release Scout marked counts.
			if ( '' === $installed ? $version === $fix['version'] : version_compare( $installed, $fix['version'], '<' ) ) {
				return true;
			}
		}
		return false;
	}

	/** The version this site runs now, or '' when it cannot tell. */
	public static function installed( string $key ): string {
		if ( 0 === strpos( $key, self::THEME ) ) {
			$theme = wp_get_theme( substr( $key, strlen( self::THEME ) ) );
			return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		}
		$checked = (array) ( get_site_transient( 'update_plugins' )->checked ?? array() );
		foreach ( $checked as $file => $version ) {
			if ( dirname( (string) $file ) === $key ) {
				return (string) $version;
			}
		}
		return '';
	}

	/** Why this version waits for an OK, or '' when it does not. */
	public static function reason( string $key, string $version ): string {
		if ( '' === $key || '' === $version || ! self::on() || self::approved( $key, $version ) || self::is_security( $key, $version ) ) {
			return '';
		}
		return __( 'Waiting for your OK. Check it on staging first, then approve it below.', 'scout-build' );
	}

	/** An approved version or a security fix installs without waiting for its ring. */
	public static function skips_ring( string $key, string $version ): bool {
		return '' !== $key && '' !== $version && ( self::is_security( $key, $version ) || ( self::on() && self::approved( $key, $version ) ) );
	}

	/**
	 * Hold an automatic update of a theme that updates from GitHub until it is approved.
	 *
	 * @param bool|null $update Whether to auto-update, as decided so far.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function filter_theme( $update, $item ) {
		$sheet = isset( $item->theme ) ? (string) $item->theme : '';
		$ver   = (string) ( $item->new_version ?? '' );
		if ( '' === $sheet || ! self::scout_theme( $sheet ) ) {
			return $update;
		}
		return '' === self::reason( self::THEME . $sheet, $ver ) ? $update : false;
	}

	/** True for a theme that updates from GitHub through Scout Build. */
	private static function scout_theme( string $sheet ): bool {
		$theme = wp_get_theme( $sheet );
		return $theme->exists() && null !== Scout_Hub_Themes::source( $theme );
	}

	/**
	 * Everything that waits for an OK right now: Scout plugins and the theme.
	 *
	 * @return array<int, array{key:string, name:string, from:string, to:string}>
	 */
	public static function waiting(): array {
		if ( ! self::on() ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( get_site_transient( 'update_plugins' )->response ?? array() ) as $file => $offer ) {
			$id   = (string) ( $offer->id ?? '' );
			$slug = (string) ( $offer->slug ?? dirname( (string) $file ) );
			$to   = (string) ( $offer->new_version ?? '' );
			if ( 0 !== strpos( $id, 'github.com/' . Scout_Hub_Catalog::REPO . '/' ) || '' === self::reason( $slug, $to ) ) {
				continue;
			}
			$data  = function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $file ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array();
			$out[] = array( 'key' => $slug, 'name' => (string) ( $data['Name'] ?? $slug ), 'from' => (string) ( $data['Version'] ?? '' ), 'to' => $to );
		}
		foreach ( (array) ( get_site_transient( 'update_themes' )->response ?? array() ) as $sheet => $offer ) {
			$offer = (array) $offer;
			$to    = (string) ( $offer['new_version'] ?? '' );
			if ( ! self::scout_theme( (string) $sheet ) || '' === self::reason( self::THEME . $sheet, $to ) ) {
				continue;
			}
			$theme = wp_get_theme( (string) $sheet );
			/* translators: %s: theme name */
			$out[] = array( 'key' => self::THEME . $sheet, 'name' => sprintf( __( 'Theme: %s', 'scout-build' ), $theme->get( 'Name' ) ), 'from' => (string) $theme->get( 'Version' ), 'to' => $to );
		}
		return $out;
	}

	/** Record an OK and write it to the Site log. */
	public static function approve( string $key, string $version, string $name, string $note ): void {
		$all         = self::approvals();
		$user        = wp_get_current_user();
		$all[ $key ] = array(
			'version' => $version,
			'by'      => $user && $user->exists() ? (string) $user->display_name : '',
			'note'    => $note,
			'at'      => time(),
		);
		update_option( self::OPTION_OK, $all, false );
		if ( class_exists( 'Scout_Hub_Log' ) ) {
			Scout_Hub_Log::add(
				array(
					/* translators: 1: plugin or theme name, 2: version */
					'title'       => sprintf( __( 'Approved for the live site: %1$s %2$s', 'scout-build' ), $name, $version ),
					'detail'      => $note,
					/* translators: 1: plugin or theme name, 2: version */
					'client_text' => sprintf( __( '%1$s %2$s was checked on staging and approved for your live site.', 'scout-build' ), $name, $version ),
					'kind'        => 'approval',
					'topic'       => 'plugin',
					'version'     => $name . ' ' . $version,
					'source'      => 'Scout',
				)
			);
		}
	}

	/** Approve one waiting update or all of them, or change the site's setting (operators only). */
	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can approve updates.', 'scout-build' ), 403 );
		}
		$do   = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );
		$note = sanitize_text_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) );
		$msg  = '';
		if ( 'mode' === $do ) {
			$mode = sanitize_key( wp_unslash( (string) ( $_POST['mode'] ?? '' ) ) );
			if ( in_array( $mode, array( 'on', 'off' ), true ) && ( 'on' === $mode ) !== self::by_address() ) {
				update_option( self::OPTION_MODE, $mode, false );
			} else {
				delete_option( self::OPTION_MODE ); // Back to what the address says.
			}
			$msg = self::on() ? __( 'Updates on this site now wait for your OK.', 'scout-build' ) : __( 'Updates on this site install without waiting for an OK.', 'scout-build' );
		} else {
			$want  = 'all' === $do ? '' : sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) );
			$count = 0;
			foreach ( self::waiting() as $row ) {
				if ( '' === $want || $row['key'] === $want ) {
					self::approve( $row['key'], $row['to'], $row['name'], $note );
					++$count;
				}
			}
			/* translators: %d: number of updates */
			$msg = $count ? sprintf( _n( 'Approved %d update. It installs on the next update run, or press Update now.', 'Approved %d updates. They install on the next update run, or press Update now.', $count, 'scout-build' ), $count ) : __( 'Nothing was waiting.', 'scout-build' );
		}
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'updates' ) . '#sh-approval' );
		exit;
	}

	/** One Inbox row for operators while anything waits for an OK. */
	public static function inbox_row( $rows ): array {
		$rows = (array) $rows;
		if ( ! Scout_Hub_Access::is_operator() ) {
			return $rows;
		}
		$n = count( self::waiting() );
		if ( $n ) {
			$rows[] = array(
				'label' => __( 'Updates waiting for your OK', 'scout-build' ),
				'count' => $n,
				'url'   => Scout_Hub_Admin::url( 'updates' ) . '#sh-approval',
				'note'  => __( 'Releases that passed on staging and wait for Scout to approve them for the live site.', 'scout-build' ),
			);
		}
		return $rows;
	}

	/** The box on Scout tools, Updates. */
	public static function panel( bool $operator ): void {
		if ( ! $operator ) {
			return;
		}
		$on      = self::on();
		$waiting = $on ? self::waiting() : array();
		$form    = static function ( string $inner ): string {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. wp_nonce_field( self::ACTION, '_wpnonce', true, false ) . $inner . '</form>';
		};
		echo '<section class="sh-box" id="sh-approval"><h2 class="sh-title">' . esc_html__( 'Waiting for your OK', 'scout-build' ) . '</h2>';
		if ( ! $on ) {
			echo '<p>' . esc_html__( 'Off on this site. Scout releases install by themselves when their turn in the update order comes up.', 'scout-build' ) . '</p>';
		} elseif ( ! $waiting ) {
			echo '<p><span class="sh-tag is-current">' . esc_html__( 'Nothing waiting', 'scout-build' ) . '</span> ' . esc_html__( 'New releases stop here until you approve them. Check each one on staging first.', 'scout-build' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'These passed their staging check? Approve them and they install here. Add who said yes, like "Client OK by text, Nov 5".', 'scout-build' ) . '</p>';
			echo '<table class="sh-table sh-table-plain"><thead><tr><th scope="col">' . esc_html__( 'Update', 'scout-build' ) . '</th><th scope="col">' . esc_html__( 'Now', 'scout-build' ) . '</th><th scope="col">' . esc_html__( 'New', 'scout-build' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Approve', 'scout-build' ) . '</span></th></tr></thead><tbody>';
			foreach ( $waiting as $row ) {
				echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( '' !== $row['from'] ? 'v' . $row['from'] : '' ) . '</td><td>' . esc_html( 'v' . $row['to'] ) . '</td><td>';
				echo $form( '<input type="hidden" name="key" value="' . esc_attr( $row['key'] ) . '"><input type="hidden" name="note" value=""><button type="submit" name="do" value="one" class="button">' . esc_html__( 'Approve', 'scout-build' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			echo $form( '<label for="sh-approve-note">' . esc_html__( 'Who said yes', 'scout-build' ) . '</label> <input type="text" id="sh-approve-note" name="note" class="regular-text" placeholder="' . esc_attr__( 'Client OK by text, Nov 5', 'scout-build' ) . '"> <button type="submit" name="do" value="all" class="button button-primary">' . esc_html__( 'Approve all', 'scout-build' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		$locked = defined( 'SCOUT_HUB_APPROVAL' );
		if ( ! $locked ) {
			echo $form( '<input type="hidden" name="mode" value="' . ( $on ? 'off' : 'on' ) . '"><button type="submit" name="do" value="mode" class="button sh-quiet">' . ( $on ? esc_html__( 'Install without waiting for an OK', 'scout-build' ) : esc_html__( 'Wait for my OK before installing', 'scout-build' ) ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		echo '<p class="sh-note">' . esc_html__( 'Live sites wait for an OK; staging never does. Security fixes Scout marks in catalog.json install at once. Update now always works.', 'scout-build' ) . ( $locked ? ' ' . esc_html__( 'Set in wp-config.php (SCOUT_HUB_APPROVAL).', 'scout-build' ) : '' ) . '</p>';
		echo '</section>';
	}
}
