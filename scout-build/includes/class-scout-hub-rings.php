<?php
/**
 * Release rings and the pause switch (Scout Site Standard rule 19, §10).
 *
 * A Scout plugin release reaches scoutraleigh.com (and any staging copy) first,
 * Recon a day later and client sites a day after that. Until a site's turn the
 * release waits: WordPress still lists it, an operator can still press Update
 * now, but the automatic update holds back. Two more brakes stop it everywhere:
 *
 *   - Pause on one site: Build, Updates, "Pause automatic updates" (7 days).
 *   - Hold a release on every site: a "hold" entry in catalog.json, e.g.
 *     { "slug": "scout-build", "version": "0.10.0", "note": "Fix coming" }
 *     ("version": "*" holds every version of that plugin). Sites read the
 *     catalog within 12 hours, or at once with Check for new versions.
 *
 * The ring comes from SCOUT_HUB_RING in wp-config.php (1, 2 or 3), else the
 * setting on the Updates screen, else the address: scoutraleigh.com and staging
 * are 1, scoutrecon.app is 2, everything else is 3. A release whose date the
 * version list does not carry is never held by its ring, so an older feed
 * behaves exactly as before.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Rings {

	const OPTION_RING  = 'scout_hub_ring';
	const OPTION_PAUSE = 'scout_hub_pause_until';
	const ACTION       = 'scout_hub_rings';
	const PAUSE_DAYS   = 7;

	/**
	 * Ring number => where it sits in the update order (shown after "Update order:"),
	 * and how many days after a release it reaches that ring. The numbers are the
	 * saved values; people only ever read the update order wording.
	 */
	const RINGS = array(
		1 => array( 'first (scoutraleigh.com and staging)', 0 ),
		2 => array( 'a day later (Scout Recon)', 1 ),
		3 => array( 'two days later (client sites)', 2 ),
	);

	public static function boot(): void {
		// After Scout Build's own holds (priority 20), so a version sent back stays held either way.
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter' ), 30, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/** This site's ring, 1 to 3. */
	public static function ring(): int {
		if ( defined( 'SCOUT_HUB_RING' ) && isset( self::RINGS[ (int) SCOUT_HUB_RING ] ) ) {
			return (int) SCOUT_HUB_RING;
		}
		$saved = (int) get_option( self::OPTION_RING, 0 );
		if ( isset( self::RINGS[ $saved ] ) ) {
			return $saved;
		}
		return self::guess();
	}

	/** The ring the address suggests. */
	public static function guess(): int {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)scoutraleigh\.com$/', $host ) || Scout_Hub_Health::is_staging() ) {
			return 1;
		}
		if ( preg_match( '/(^|\.)scoutrecon\.app$/', $host ) ) {
			return 2;
		}
		return 3;
	}

	/** Days after its release that a version reaches this site. */
	public static function delay_days(): int {
		$ring = self::ring();
		/**
		 * Filters how many days after a release this site's ring installs it.
		 *
		 * @param int $days Days.
		 * @param int $ring The site's ring.
		 */
		return max( 0, (int) apply_filters( 'scout_hub_ring_delay', (int) self::RINGS[ $ring ][1], $ring ) );
	}

	/** Unix time the pause on this site ends, or 0. */
	public static function paused_until(): int {
		$until = (int) get_option( self::OPTION_PAUSE, 0 );
		return $until > time() ? $until : 0;
	}

	/**
	 * Why an automatic update of this version waits, or '' when it may install.
	 * Each answer is one plain sentence for the Updates screen.
	 */
	public static function wait_reason( string $slug, string $version ): string {
		if ( '' === $slug || '' === $version ) {
			return '';
		}
		foreach ( Scout_Hub_Catalog::holds() as $hold ) {
			if ( $hold['slug'] === $slug && ( '*' === $hold['version'] || $hold['version'] === $version ) ) {
				/* translators: %s: the hold note from catalog.json, or nothing */
				return trim( sprintf( __( 'Held on every site by Scout. %s', 'scout-build' ), $hold['note'] ) );
			}
		}
		$until = self::paused_until();
		if ( $until ) {
			/* translators: %s: date */
			return sprintf( __( 'Automatic updates are paused on this site until %s.', 'scout-build' ), wp_date( 'M j', $until ) );
		}
		// A live site waits for Scout's OK; an approved version or a security fix skips its ring.
		$ok = class_exists( 'Scout_Hub_Approval' ) ? Scout_Hub_Approval::reason( $slug, $version ) : '';
		if ( '' !== $ok ) {
			return $ok;
		}
		if ( class_exists( 'Scout_Hub_Approval' ) && Scout_Hub_Approval::skips_ring( $slug, $version ) ) {
			return '';
		}
		$lands = self::lands_at( $slug, $version );
		if ( $lands > time() ) {
			/* translators: 1: date, 2: place in the update order, like "two days later (client sites)" */
			return sprintf( __( 'Installs by itself %1$s. Update order: %2$s.', 'scout-build' ), wp_date( 'M j', $lands ), self::RINGS[ self::ring() ][0] );
		}
		return '';
	}

	/** When a version reaches this site's ring; 0 when its release date is unknown. */
	public static function lands_at( string $slug, string $version ): int {
		$published = Scout_Hub_Catalog::published( $slug, $version );
		return $published ? $published + self::delay_days() * DAY_IN_SECONDS : 0;
	}

	/**
	 * Hold an automatic update of a Scout plugin until its ring's turn, while
	 * the site is paused, or while catalog.json holds it. Manual updates are
	 * never stopped, and every other plugin passes through untouched.
	 *
	 * @param bool|null $update Whether to auto-update, as decided so far.
	 * @param object    $item   The update offer.
	 * @return bool|null
	 */
	public static function filter( $update, $item ) {
		$id = isset( $item->id ) ? (string) $item->id : '';
		if ( 0 !== strpos( $id, 'github.com/' . Scout_Hub_Catalog::REPO . '/' ) ) {
			return $update;
		}
		$slug    = (string) ( $item->slug ?? substr( $id, strlen( 'github.com/' . Scout_Hub_Catalog::REPO . '/' ) ) );
		$version = (string) ( $item->new_version ?? '' );
		return '' === self::wait_reason( $slug, $version ) ? $update : false;
	}

	/** Save the ring, or start or end a pause (operators only). */
	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! Scout_Hub_Access::is_operator() ) {
			wp_die( esc_html__( 'Only the people who look after this site can change how it updates.', 'scout-build' ), 403 );
		}
		$do = sanitize_key( wp_unslash( (string) ( $_POST['do'] ?? '' ) ) );
		if ( 'pause' === $do ) {
			update_option( self::OPTION_PAUSE, time() + self::PAUSE_DAYS * DAY_IN_SECONDS, false );
			$msg = __( 'Automatic updates are paused here for 7 days. Update now still works.', 'scout-build' );
		} elseif ( 'resume' === $do ) {
			delete_option( self::OPTION_PAUSE );
			$msg = __( 'Automatic updates are back on.', 'scout-build' );
		} else {
			$ring = (int) ( $_POST['ring'] ?? 0 );
			if ( isset( self::RINGS[ $ring ] ) && $ring !== self::guess() ) {
				update_option( self::OPTION_RING, $ring, false );
			} else {
				delete_option( self::OPTION_RING ); // Back to what the address says.
			}
			/* translators: %s: place in the update order, like "two days later (client sites)" */
			$msg = sprintf( __( 'Saved. Update order: %s.', 'scout-build' ), self::RINGS[ self::ring() ][0] );
		}
		set_transient( 'scout_hub_notice_' . get_current_user_id(), array( 'message' => $msg, 'type' => 'success' ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Scout_Hub_Admin::url( 'updates' ) . '#sh-rings' );
		exit;
	}

	/** The box on Build, Updates. */
	public static function panel( bool $operator ): void {
		$ring   = self::ring();
		$until  = self::paused_until();
		$holds  = Scout_Hub_Catalog::holds();
		$locked = defined( 'SCOUT_HUB_RING' );
		echo '<section class="sh-box" id="sh-rings"><h2 class="sh-title">' . esc_html__( 'When updates install', 'scout-build' ) . '</h2>';
		$days = self::delay_days();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: place in the update order, like "two days later (client sites)", 2: sentence about the delay */
				__( 'Update order: %1$s. %2$s', 'scout-build' ),
				self::RINGS[ $ring ][0],
				0 === $days
					? __( 'Scout releases install here first, as soon as they are out.', 'scout-build' )
					/* translators: %d: days */
					: sprintf( _n( 'Scout releases install here %d day after they are out, once the sites before this one have them.', 'Scout releases install here %d days after they are out, once the sites before this one have them.', $days, 'scout-build' ), $days )
			)
		) . '</p>';
		if ( $until ) {
			/* translators: %s: date */
			echo '<p><span class="sh-tag is-held">' . esc_html( sprintf( __( 'Paused until %s', 'scout-build' ), wp_date( 'M j', $until ) ) ) . '</span></p>';
		}
		foreach ( $holds as $hold ) {
			/* translators: 1: plugin slug, 2: version or "every version" */
			echo '<p><span class="sh-tag is-held">' . esc_html( sprintf( __( 'Held by Scout: %1$s %2$s', 'scout-build' ), $hold['slug'], '*' === $hold['version'] ? __( '(every version)', 'scout-build' ) : $hold['version'] ) ) . '</span> ' . esc_html( $hold['note'] ) . '</p>';
		}
		if ( $operator ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			if ( ! $locked ) {
				// Short options: the sentence above already says what each place means (Joy, 2026-11-04).
				$short = array( 1 => __( 'Update order: first', 'scout-build' ), 2 => __( 'Update order: a day later', 'scout-build' ), 3 => __( 'Update order: two days later', 'scout-build' ) );
				echo '<label for="sh-ring" class="screen-reader-text">' . esc_html__( 'Update order', 'scout-build' ) . '</label><select id="sh-ring" name="ring">';
				foreach ( self::RINGS as $n => $meta ) {
					echo '<option value="' . (int) $n . '"' . selected( $ring, $n, false ) . '>' . esc_html( $short[ $n ] ?? $meta[0] ) . '</option>';
				}
				echo '</select> <button type="submit" name="do" value="ring" class="button">' . esc_html__( 'Save', 'scout-build' ) . '</button>';
			}
			if ( $until ) {
				echo ' <button type="submit" name="do" value="resume" class="button">' . esc_html__( 'Turn automatic updates back on', 'scout-build' ) . '</button>';
			} else {
				echo ' <button type="submit" name="do" value="pause" class="button sh-quiet">' . esc_html__( 'Pause automatic updates for 7 days', 'scout-build' ) . '</button>';
			}
			echo '</form>';
			/* translators: %s: place in the update order, like "two days later (client sites)" */
			echo '<p class="sh-note">' . esc_html( sprintf( __( 'Suggested for this address: %s.', 'scout-build' ), self::RINGS[ self::guess() ][0] ?? '' ) ) . '</p>';
			echo '<p class="sh-note">' . esc_html__( 'A pause stops automatic updates on this site only. Update now still works.', 'scout-build' ) . ( $locked ? ' ' . esc_html__( 'The update order is set in wp-config.php (SCOUT_HUB_RING).', 'scout-build' ) : '' ) . '</p>';
		}
		echo '</section>';
	}
}
