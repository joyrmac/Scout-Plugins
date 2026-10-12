<?php
/**
 * Where the Reviews screens live in the dashboard.
 *
 * On a site with a Build section (Scout Build, Recon Build, Site Build), every
 * Reviews screen sits inside it, per the dashboard standard. Anywhere else the
 * plugin keeps its own Reviews menu.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scout_Reviews_Menu {

	/** Runs after the theme (10), the Scout Build hub (15) and the other features (30), so the Reviews screens sit together. */
	const PRIORITY = 31;

	/** Remembers the Build section's slug for screens that never build the menu. */
	const OPTION = 'scout_reviews_build_parent';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), self::PRIORITY );
		add_filter( 'parent_file', array( __CLASS__, 'highlight' ) );
	}

	public static function list_slug(): string {
		return 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE;
	}

	/** The Build section's slug, or '' when the site has none. */
	public static function build(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/** Parent for the Reviews screens. */
	public static function parent(): string {
		$build = self::build();
		return '' !== $build ? $build : self::list_slug();
	}

	/** Link to one of the Reviews screens, wherever it lives. */
	public static function url( string $page, string $hash = '' ): string {
		// Outside a normal screen (admin-post redirects) the menu is never built,
		// so fall back to where it was the last time it was.
		$build = did_action( 'admin_menu' ) ? self::build() : (string) get_option( self::OPTION, '' );
		$base  = '' !== $build ? admin_url( 'admin.php?page=' . $page ) : admin_url( self::list_slug() . '&page=' . $page );
		return $base . ( '' !== $hash ? '#' . $hash : '' );
	}

	public static function menu(): void {
		$build = self::build();
		if ( get_option( self::OPTION, '' ) !== $build ) {
			update_option( self::OPTION, $build, true );
		}
		if ( '' === $build ) {
			return;
		}

		// Old bookmarks (Reviews -> Settings and friends) land on the new screen.
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit.php' === $pagenow && in_array( $page, self::pages(), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
			exit;
		}

		// Drop the plugin's own menu, submenu included, so WordPress files the
		// review screens under Build instead of the old Reviews menu.
		global $submenu;
		remove_menu_page( self::list_slug() );
		unset( $submenu[ self::list_slug() ] );
		add_submenu_page( $build, __( 'Reviews', 'scout-reviews' ), __( 'Reviews', 'scout-reviews' ), 'edit_posts', self::list_slug() );
	}

	/** @return string[] */
	private static function pages(): array {
		return array( Scout_Reviews_Settings::PAGE, Scout_Reviews_Requests::PAGE, Scout_Reviews_Inbox::PAGE );
	}

	/** Keep the Build section open while editing a review. */
	public static function highlight( $file ) {
		global $submenu_file;
		$build  = self::build();
		$screen = get_current_screen();
		if ( '' !== $build && $screen && Scout_Reviews_Post_Type::TYPE === $screen->post_type && '' === (string) ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$submenu_file = self::list_slug(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return $build;
		}
		return $file;
	}
}
