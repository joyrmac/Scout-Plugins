<?php
/**
 * Plugin Name:       Scout Forms Guard
 * Description:       In-house spam protection for Gravity Forms: a hidden honeypot, a time trap, and a signed token. No reCAPTCHA, no Akismet, no third-party calls.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  gravityforms
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-forms-guard
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-forms-guard
 *
 * @package Scout_Forms_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
// Loaded on plugins_loaded, after every plugin's own file, so older Scout
// plugins that still declare the shared class at load time always go first.
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-forms-guard.php', 'scout-forms-guard' );
	},
	0
);

final class Scout_Forms_Guard {

	const HP  = 'scout_hp';   // Honeypot field.
	const TS  = 'scout_ts';   // Render timestamp.
	const SIG = 'scout_sig';  // HMAC of timestamp + form id.
	const LOG = 'scout_forms_guard_blocked'; // Blocked count per month: array( 'Y-m' => n ).

	/** @var bool Did we block the current submission? */
	private static $blocked = false;

	public static function init() {
		add_filter( 'gform_get_form_filter', array( __CLASS__, 'inject' ), 10, 2 );
		add_filter( 'gform_validation', array( __CLASS__, 'validate' ) );
		add_filter( 'gform_validation_message', array( __CLASS__, 'message' ), 10, 2 );
	}

	/**
	 * Sign a form id + timestamp with the site's secret salt.
	 */
	private static function sign( $form_id, $ts ) {
		return hash_hmac( 'sha256', $form_id . '|' . $ts, wp_salt( 'auth' ) );
	}

	/**
	 * Add the hidden honeypot + signed-timestamp fields to every form.
	 */
	public static function inject( $form_string, $form ) {
		if ( empty( $form['id'] ) ) {
			return $form_string;
		}
		$form_id = (int) $form['id'];
		$ts      = time();
		$sig     = self::sign( $form_id, $ts );

		$fields  = '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">';
		$fields .= '<label>Leave this field empty<input type="text" name="' . esc_attr( self::HP ) . '" value="" tabindex="-1" autocomplete="off" /></label>';
		$fields .= '</div>';
		$fields .= '<input type="hidden" name="' . esc_attr( self::TS ) . '" value="' . esc_attr( $ts ) . '" />';
		$fields .= '<input type="hidden" name="' . esc_attr( self::SIG ) . '" value="' . esc_attr( $sig ) . '" />';

		$pos = strripos( $form_string, '</form>' );
		if ( false === $pos ) {
			return $form_string . $fields;
		}
		return substr( $form_string, 0, $pos ) . $fields . substr( $form_string, $pos );
	}

	/**
	 * Block the submission if the honeypot is filled, the token is missing or
	 * forged, or the form was submitted impossibly fast or impossibly late.
	 */
	public static function validate( $validation_result ) {
		$form    = isset( $validation_result['form'] ) ? $validation_result['form'] : array();
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		// 1. Honeypot must be empty.
		$honeypot = isset( $_POST[ self::HP ] ) ? trim( (string) wp_unslash( $_POST[ self::HP ] ) ) : '';
		if ( '' !== $honeypot ) {
			return self::fail( $validation_result );
		}

		// 2 + 3. The signed timestamp must verify and sit inside the time window.
		$ts  = isset( $_POST[ self::TS ] ) ? (int) $_POST[ self::TS ] : 0;
		$sig = isset( $_POST[ self::SIG ] ) ? (string) wp_unslash( $_POST[ self::SIG ] ) : '';

		if ( ! $ts || ! $sig || ! hash_equals( self::sign( $form_id, $ts ), $sig ) ) {
			return self::fail( $validation_result );
		}

		$min     = (int) apply_filters( 'scout_forms_guard_min_seconds', 3 );
		$max     = (int) apply_filters( 'scout_forms_guard_max_seconds', DAY_IN_SECONDS );
		$elapsed = time() - $ts;
		if ( $elapsed < $min || $elapsed > $max ) {
			return self::fail( $validation_result );
		}

		return $validation_result;
	}

	private static function fail( $validation_result ) {
		$validation_result['is_valid'] = false;
		self::$blocked                 = true;
		self::count();
		do_action( 'scout_forms_guard_blocked', isset( $validation_result['form'] ) ? $validation_result['form'] : array() );
		return $validation_result;
	}

	/** Add one to this month's blocked count. Keeps the last 13 months. */
	private static function count() {
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$month = wp_date( 'Y-m' );
		$log[ $month ] = ( isset( $log[ $month ] ) ? (int) $log[ $month ] : 0 ) + 1;
		krsort( $log );
		update_option( self::LOG, array_slice( $log, 0, 13, true ), false );
	}

	/**
	 * Spam submissions blocked in a month ('Y-m', default this month in the
	 * site's timezone), or across every month kept when $month is 'all'.
	 * Scout Build shows this on the Dashboard.
	 */
	public static function blocked( $month = '' ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		if ( 'all' === $month ) {
			return (int) array_sum( array_map( 'intval', $log ) );
		}
		$month = '' === $month ? wp_date( 'Y-m' ) : (string) $month;
		return isset( $log[ $month ] ) ? (int) $log[ $month ] : 0;
	}

	/**
	 * Friendly, generic message when we blocked a submission (so a falsely
	 * caught human knows to retry, without telling a bot why it failed).
	 */
	public static function message( $message, $form ) {
		if ( self::$blocked ) {
			return '<div class="validation_error">Your submission could not be verified. Please reload the page and try again.</div>';
		}
		return $message;
	}
}

add_action( 'init', array( 'Scout_Forms_Guard', 'init' ) );
