<?php
/**
 * Scout Newsletter uninstall cleanup. Runs only when the plugin is deleted.
 *
 * The settings, the saved key and the activity log always go. The consent
 * records are the proof of who agreed to the newsletter, so they stay unless
 * the site defines SCOUT_PURGE_ON_UNINSTALL as true in wp-config.php first
 * (download them from Scout, Newsletter before purging).
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'scout_newsletter', 'scout_newsletter_key', 'scout_newsletter_log', 'scout_newsletter_unsubscribed' ) as $scout_newsletter_option ) {
	delete_option( $scout_newsletter_option );
}
delete_transient( 'scout_newsletter_test' );
delete_transient( 'scout_newsletter_lock' );
delete_transient( 'scout_updater_scout-newsletter' );
wp_clear_scheduled_hook( 'scout_newsletter_run' );
wp_clear_scheduled_hook( 'scout_newsletter_sweep' );

if ( ! defined( 'SCOUT_PURGE_ON_UNINSTALL' ) || ! SCOUT_PURGE_ON_UNINSTALL ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scout_newsletter" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'scout_newsletter_db' );
