<?php
/**
 * Remove the bookings table, the session types and the settings on delete.
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
