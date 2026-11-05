<?php
/**
 * Scout People (scout-team) uninstall cleanup. Runs only when the plugin is deleted.
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

// Links people fill in their own details with, and details that were still
// waiting for an OK: a link must never outlive the plugin that checks it.
// Photos sent with them stay in the media library, like every photo.
delete_metadata( 'post', 0, '_scout_team_link_hash', '', true );
delete_metadata( 'post', 0, '_scout_team_link', '', true );
delete_metadata( 'post', 0, '_scout_team_pending', '', true );

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

// The record that the move to People ran. It stays when profiles stay, so a
// reinstall never puts people back in groups someone took them out of.
// Groups (the scout_people_group terms) are never deleted.
delete_option( 'scout_team_db' );
