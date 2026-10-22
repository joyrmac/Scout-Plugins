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

	const PLAN_OPTION = 'scout_hub_plan';

	/** Plans in order, so a feature included in a lower plan is in every higher one. */
	const PLANS = array(
		'build'       => 'The Build',
		'partnership' => 'The Partnership',
		'whole'       => 'The Whole System',
	);

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
	 * Is a feature with this tier part of the site's plan?
	 *
	 * @param string $tier build | partnership | whole | addon.
	 */
	public static function included( string $tier ): bool {
		$plan = self::plan();
		if ( '' === $plan ) {
			return false;
		}
		// The Whole System includes every add-on (Joy, 2026-10-13).
		if ( 'addon' === $tier ) {
			return 'whole' === $plan;
		}
		if ( ! isset( self::PLANS[ $tier ] ) ) {
			return false;
		}
		$order = array_keys( self::PLANS );
		return array_search( $tier, $order, true ) <= array_search( $plan, $order, true );
	}

	/** The label a feature card shows for its tier. */
	public static function tier_label( string $tier ): string {
		if ( self::included( $tier ) ) {
			return __( 'Included in your plan', 'scout-build' );
		}
		if ( isset( self::PLANS[ $tier ] ) ) {
			/* translators: %s: plan name */
			return sprintf( __( 'Included with %s', 'scout-build' ), self::PLANS[ $tier ] );
		}
		return __( 'Add-on', 'scout-build' );
	}
}
