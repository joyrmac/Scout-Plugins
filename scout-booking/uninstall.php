<?php
/**
 * Remove the bookings table, the session types, the settings and the saved
 * calendar addresses on delete.
 * WooCommerce products made for session types are left for the shop owner,
 * since past orders point at them.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'scout_bookings' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'scout_booking' );
delete_option( 'scout_booking_types' );
delete_option( 'scout_booking_db' );
wp_clear_scheduled_hook( 'scout_booking_hourly' );
// Busy times from the owner's calendar: the private addresses, their notes and the saved busy times.
delete_option( 'scout_booking_busy_feeds' );
delete_option( 'scout_booking_busy_state' );
delete_transient( 'scout_booking_busy' );
delete_transient( 'scout_booking_busy_lock' );
wp_clear_scheduled_hook( 'scout_booking_busy_refresh' );
