<?php
/**
 * Remove the hours, closures and dismissed reminders on delete. Visitors'
 * "hide this notice" choice lives in their own browser for one visit.
 *
 * @package Scout_Hours
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_hours' );
delete_option( 'scout_hours_closures' );
delete_option( 'scout_hours_dismissed' );
