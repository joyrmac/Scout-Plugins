<?php
/**
 * Staging guard: a staging copy never reaches real people.
 *
 * A staging copy is made from the live site, so it carries the live site's
 * customers, members, bookings and keys, and its scheduled jobs keep running.
 * On a staging copy:
 * - email goes only to Scout (scoutraleigh.com addresses, plus any added with
 *   the scout_core_staging_mail_to filter). Mail to anyone else is dropped, and
 *   so are Cc and Bcc copies to them.
 * - calls to outside services that send or charge (newsletter, email
 *   marketing, texting, payments, Zapier) are refused, so a test sign-up never
 *   joins the real list and a test checkout never charges a card.
 * - a notice at the top of every dashboard screen says so.
 *
 * A copy counts as staging when WordPress says so (WP_ENVIRONMENT_TYPE staging,
 * development or local) or when its address is a host's staging address.
 * define( 'SCOUT_STAGING', false ) in wp-config.php marks a site live,
 * define( 'SCOUT_STAGING', true ) marks it staging.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Staging {

	const LOG = 'scout_core_staging_held'; // { mail: n, calls: n, last: time }.

	/** Staging addresses of the hosts Scout uses. */
	const HOSTS = array( '.wpenginepowered.com', '.wpengine.com', '.kinsta.cloud', '.flywheelsites.com', '.pantheonsite.io', '.cloudwaysapps.com' );

	/** Outside services that send messages or take money. Matched against the end of the host. */
	const BLOCKED = array( 'api.beehiiv.com', 'api.mailchimp.com', 'list-manage.com', 'api.sendgrid.com', 'api.mailgun.net', 'api.postmarkapp.com', 'api.twilio.com', 'api.stripe.com', 'api-m.paypal.com', 'api.paypal.com', 'hooks.zapier.com', 'api.hubapi.com', 'api.convertkit.com', 'a.klaviyo.com' );

	public static function init() {
		if ( ! self::is_staging() ) {
			return;
		}
		add_filter( 'wp_mail', array( __CLASS__, 'mail_args' ), 999 );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'stop_empty_mail' ), 999, 2 );
		add_filter( 'pre_http_request', array( __CLASS__, 'stop_calls' ), 999, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function is_staging( string $env = '', string $host = '' ) {
		if ( defined( 'SCOUT_STAGING' ) ) {
			return (bool) SCOUT_STAGING;
		}
		$env  = '' !== $env ? $env : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production' );
		$host = '' !== $host ? $host : strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( in_array( $env, array( 'staging', 'development', 'local' ), true ) ) {
			return true;
		}
		foreach ( self::HOSTS as $suffix ) {
			if ( '' !== $host && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/** True when an address may get mail from a staging copy. */
	public static function allowed( string $address ) {
		if ( preg_match( '/<([^>]+)>/', $address, $m ) ) {
			$address = $m[1];
		}
		$address = strtolower( trim( $address ) );
		$domains = (array) apply_filters( 'scout_core_staging_mail_to', array( 'scoutraleigh.com' ) );
		foreach ( $domains as $domain ) {
			$domain = strtolower( ltrim( (string) $domain, '@' ) );
			if ( '' !== $domain && ( $address === $domain || substr( $address, -strlen( '@' . $domain ) ) === '@' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/** Keep only Scout recipients, in To, Cc and Bcc. */
	public static function mail_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$to     = is_array( $args['to'] ?? '' ) ? $args['to'] : explode( ',', (string) ( $args['to'] ?? '' ) );
		$kept   = array_values( array_filter( array_map( 'trim', $to ), array( __CLASS__, 'allowed' ) ) );
		$before = count( array_filter( array_map( 'trim', $to ) ) );
		$args['to'] = $kept;
		$headers    = is_array( $args['headers'] ?? '' ) ? $args['headers'] : preg_split( "/\r\n|\n/", (string) ( $args['headers'] ?? '' ) );
		$clean      = array();
		foreach ( (array) $headers as $line ) {
			if ( preg_match( '/^\s*(cc|bcc)\s*:\s*(.*)$/i', (string) $line, $m ) ) {
				$keep = array_filter( array_map( 'trim', explode( ',', $m[2] ) ), array( __CLASS__, 'allowed' ) );
				if ( $keep ) {
					$clean[] = $m[1] . ': ' . implode( ', ', $keep );
				}
				continue;
			}
			$clean[] = $line;
		}
		$args['headers'] = $clean;
		if ( count( $kept ) < $before ) {
			self::count( 'mail' );
		}
		return $args;
	}

	/** With nobody left to send to, the message is not sent at all. */
	public static function stop_empty_mail( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}
		$to = is_array( $atts['to'] ?? '' ) ? $atts['to'] : array_filter( explode( ',', (string) ( $atts['to'] ?? '' ) ) );
		return $to ? null : false;
	}

	/** Refuse calls to services that send messages or take money. */
	public static function stop_calls( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		foreach ( (array) apply_filters( 'scout_core_staging_blocked_hosts', self::BLOCKED ) as $blocked ) {
			$blocked = strtolower( (string) $blocked );
			if ( '' !== $host && ( $host === $blocked || substr( $host, -strlen( '.' . $blocked ) ) === '.' . $blocked ) ) {
				self::count( 'calls' );
				return new WP_Error( 'scout_staging', 'This is a staging copy, so outside services that send messages or take payments are switched off.' );
			}
		}
		return $pre;
	}

	private static function count( string $what ) {
		$log          = get_option( self::LOG, array() );
		$log          = is_array( $log ) ? $log : array();
		$log[ $what ] = (int) ( $log[ $what ] ?? 0 ) + 1;
		$log['last']  = time();
		update_option( self::LOG, $log, false );
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Staging copy.', 'scout-core' ) . '</strong> ' . esc_html( ( ! method_exists( 'Scout_Admin_UI', 'branded' ) || Scout_Admin_UI::branded() ) ? __( 'Emails go only to Scout, and newsletter, texting and payment services are off, so no real customer is reached.', 'scout-core' ) : __( 'Emails go only to your web team, and newsletter, texting and payment services are off, so no real customer is reached.', 'scout-core' ) ) . '</p></div>';
	}
}
