<?php
/**
 * Who may change features, and which plan the site is on.
 *
 * Operators are the people who build and look after the site (Sean and Joy by
 * default). The list lives in wp-config.php, so a client admin cannot add
 * themselves from the dashboard:
 *
 *   define( 'SCOUT_HUB_OPERATORS', 'sean@scoutraleigh.com, joy@scoutraleigh.com' );
 *
 * Everyone else with dashboard access sees what is on and can ask for more.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Access {

	const PLAN_OPTION      = 'scout_hub_plan';
	const PICKS_OPTION     = 'scout_hub_plan_picks';   // Features added to this site's plan one at a time.
	const RECOMMEND_OPTION = 'scout_hub_recommended';  // Features Scout recommends for this site.

	/** The plans an operator can set (Joy, 2026-11-04: Friends and family is internal, never shown in pricing). */
	const PLANS = array(
		'build'       => 'The Build',
		'partnership' => 'The Partnership',
		'whole'       => 'The Whole System',
		'ff'          => 'Friends and family',
	);

	/** The paid plans in order, so a feature included in a lower plan is in every higher one. */
	const ORDER = array( 'build', 'partnership', 'whole' );

	/** @return string[] Lower-case operator emails. */
	public static function operators(): array {
		$raw = defined( 'SCOUT_HUB_OPERATORS' ) ? (string) SCOUT_HUB_OPERATORS : 'sean@scoutraleigh.com, joy@scoutraleigh.com';
		$out = array();
		foreach ( explode( ',', $raw ) as $email ) {
			$email = strtolower( trim( $email ) );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return $out;
	}

	/** True for an operator who can also manage plugins on this site. */
	public static function is_operator( ?WP_User $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( strtolower( (string) $user->user_email ), self::operators(), true )
			&& user_can( $user, 'activate_plugins' );
	}

	/** Plugin installs are possible on this site (not locked by the host). */
	public static function can_install(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && current_user_can( 'install_plugins' );
	}

	public static function plan(): string {
		$plan = (string) get_option( self::PLAN_OPTION, '' );
		return isset( self::PLANS[ $plan ] ) ? $plan : '';
	}

	public static function set_plan( string $plan ): void {
		update_option( self::PLAN_OPTION, isset( self::PLANS[ $plan ] ) ? $plan : '', false );
	}

	/**
	 * Features added to this site's plan one at a time: Plus modules on any plan,
	 * and everything picked for a Friends and family site. The theme's setup file
	 * "use" list counts as picks on a Friends and family site.
	 *
	 * @return string[]
	 */
	public static function picks(): array {
		$picks = (array) get_option( self::PICKS_OPTION, array() );
		if ( 'ff' === self::plan() && class_exists( 'Scout_Hub_Setup' ) && method_exists( 'Scout_Hub_Setup', 'feature_plan' ) ) {
			$picks = array_merge( $picks, (array) ( Scout_Hub_Setup::feature_plan()['use'] ?? array() ) );
		}
		return array_values( array_unique( array_map( 'sanitize_key', $picks ) ) );
	}

	/** Add a feature to this site's plan, or take it out. */
	public static function pick( string $slug, bool $on ): void {
		$picks = array_map( 'sanitize_key', (array) get_option( self::PICKS_OPTION, array() ) );
		$picks = $on ? array_merge( $picks, array( sanitize_key( $slug ) ) ) : array_diff( $picks, array( sanitize_key( $slug ) ) );
		update_option( self::PICKS_OPTION, array_values( array_unique( $picks ) ), false );
	}

	/** Features Scout recommends for this site: the setup file's "use" list and the ones an operator marked. */
	public static function recommended(): array {
		$list = (array) get_option( self::RECOMMEND_OPTION, array() );
		if ( class_exists( 'Scout_Hub_Setup' ) && method_exists( 'Scout_Hub_Setup', 'feature_plan' ) ) {
			$list = array_merge( (array) ( Scout_Hub_Setup::feature_plan()['use'] ?? array() ), $list );
		}
		return array_values( array_unique( array_map( 'sanitize_key', $list ) ) );
	}

	/** Mark a feature recommended for this site, or not. */
	public static function recommend( string $slug, bool $on ): void {
		$list = array_map( 'sanitize_key', (array) get_option( self::RECOMMEND_OPTION, array() ) );
		$list = $on ? array_merge( $list, array( sanitize_key( $slug ) ) ) : array_diff( $list, array( sanitize_key( $slug ) ) );
		update_option( self::RECOMMEND_OPTION, array_values( array_unique( $list ) ), false );
	}

	/**
	 * Is a feature part of the site's plan?
	 *
	 * Core (build) comes with every plan. Plan features (partnership, whole)
	 * come with that plan and up. Bonus features come with The Whole System and
	 * are added on us elsewhere when they fit. Plus modules are never bundled:
	 * they are picked one at a time (Joy, 2026-11-04). The legacy addon tier
	 * reads as bonus.
	 *
	 * @param string $tier build | partnership | whole | bonus | plus | addon.
	 * @param string $slug The feature, so a pick counts.
	 */
	public static function included( string $tier, string $slug = '' ): bool {
		if ( '' !== $slug && in_array( $slug, self::picks(), true ) ) {
			return true;
		}
		$plan = self::plan();
		if ( '' === $plan ) {
			return false;
		}
		if ( 'ff' === $plan ) {
			return 'build' === $tier;
		}
		if ( in_array( $tier, array( 'bonus', 'addon' ), true ) ) {
			return 'whole' === $plan;
		}
		if ( ! in_array( $tier, self::ORDER, true ) ) {
			return false; // Plus, and anything unknown.
		}
		return array_search( $tier, self::ORDER, true ) <= array_search( $plan, self::ORDER, true );
	}

	/** The label a feature card shows for its tier. */
	public static function tier_label( string $tier, string $slug = '' ): string {
		if ( '' !== $slug && in_array( $slug, self::picks(), true ) ) {
			return __( 'Picked for this site', 'scout-build' );
		}
		if ( self::included( $tier, $slug ) ) {
			return __( 'Included in your plan', 'scout-build' );
		}
		if ( in_array( $tier, array( 'bonus', 'addon' ), true ) ) {
			return __( 'Bonus from Scout', 'scout-build' );
		}
		if ( 'plus' === $tier ) {
			return __( 'Plus: added on its own', 'scout-build' );
		}
		if ( in_array( $tier, self::ORDER, true ) ) {
			/* translators: %s: plan name */
			return sprintf( __( 'Included with %s', 'scout-build' ), self::PLANS[ $tier ] );
		}
		return __( 'Add-on', 'scout-build' );
	}
}
