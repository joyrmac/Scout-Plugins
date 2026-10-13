<?php
/**
 * Scout Redirects uninstall cleanup. Runs only when the plugin is deleted.
 *
 * The missing-page log, the settings and the counters always go. The
 * redirects themselves stay, like other content in Scout plugins, because
 * old addresses keep earning search traffic and reinstalling brings them
 * straight back. Define SCOUT_PURGE_ON_UNINSTALL as true in wp-config.php
 * first to remove them too.
 *
 * @package Scout_Redirects
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( defined( 'SCOUT_PURGE_ON_UNINSTALL' ) && SCOUT_PURGE_ON_UNINSTALL ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scout_redirects" );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scout_404_log" );
// The page-moved notices are short-lived transients, one per user.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_scout\\_redirects\\_auto\\_%' OR option_name LIKE '\\_transient\\_timeout\\_scout\\_redirects\\_auto\\_%'" );
// phpcs:enable

delete_option( 'scout_redirects_settings' );
delete_option( 'scout_redirects_count' );
