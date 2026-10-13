<?php
/**
 * Scout Team uninstall cleanup. Runs only when the plugin is deleted.
 *
 * Conservative on purpose, like Scout Core and Scout Reviews: profiles are
 * content someone typed in, so they stay unless the site defines
 * SCOUT_PURGE_ON_UNINSTALL as true in wp-config.php first. The settings
 * always go. Photos always stay in the media library.
 *
 * @package Scout_Team
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_team' );
delete_transient( 'scout_updater_scout-team' );

if ( ! defined( 'SCOUT_PURGE_ON_UNINSTALL' ) || ! SCOUT_PURGE_ON_UNINSTALL ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on delete.
$scout_team_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'scout_team' ) );
foreach ( $scout_team_ids as $scout_team_id ) {
	wp_delete_post( (int) $scout_team_id, true );
}

// Any profile meta left behind (for example on revisions).
delete_metadata( 'post', 0, '_scout_team', '', true );
