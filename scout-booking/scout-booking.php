<?php
/**
 * Plugin Name:       Scout Booking
 * Description:       Private sessions booked on the site: session types the owner edits, open times from the working hours, a booking form with short intake questions, requests the owner approves, deposits through the WooCommerce checkout, reschedule and cancel links, and a private calendar feed for the owner's phone. Without WooCommerce every booking is a request.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Scout Media & Consulting
 * License:           GPL-2.0-or-later
 * Text Domain:       scout-booking
 * Update URI:        https://github.com/joyrmac/Scout-Plugins/scout-booking
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUT_BOOKING_VERSION', '0.1.0' );
define( 'SCOUT_BOOKING_FILE', __FILE__ );
define( 'SCOUT_BOOKING_URL', plugin_dir_url( __FILE__ ) );

// Self-updating from the Scout Plugins releases (see includes/class-scout-plugin-updater.php).
add_action(
	'plugins_loaded',
	static function () {
		require_once __DIR__ . '/includes/class-scout-plugin-updater.php';
		Scout_Plugin_Updater::boot( __DIR__ . '/scout-booking.php', 'scout-booking' );
	},
	0
);

require_once __DIR__ . '/includes/class-scout-admin-ui.php';
require_once __DIR__ . '/includes/class-scout-booking.php';
require_once __DIR__ . '/includes/class-scout-booking-slots.php';
require_once __DIR__ . '/includes/class-scout-booking-emails.php';
require_once __DIR__ . '/includes/class-scout-booking-front.php';
require_once __DIR__ . '/includes/class-scout-booking-woo.php';
require_once __DIR__ . '/includes/class-scout-booking-admin.php';
require_once __DIR__ . '/includes/class-scout-booking-types-admin.php';

Scout_Booking::boot();
Scout_Booking_Front::boot();
Scout_Booking_Admin::boot();
Scout_Booking_Types_Admin::boot();
// WooCommerce loads after this plugin in alphabetical order, so check for it later.
add_action( 'plugins_loaded', array( 'Scout_Booking_Woo', 'boot' ), 20 );

register_activation_hook( __FILE__, array( 'Scout_Booking', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Scout_Booking', 'unschedule' ) );
