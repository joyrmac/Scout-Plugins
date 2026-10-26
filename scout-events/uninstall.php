<?php
/**
 * Deleting the plugin removes its settings only. Events, sessions and
 * speakers are the client's content and stay in the database, so putting
 * the plugin back brings every page back as it was.
 *
 * @package Scout_Events
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_events' );
delete_option( 'scout_events_rewrite' );
