<?php
/**
 * Scout Build uninstall: settings and caches only. Change requests are the
 * client's own record, so they stay.
 *
 * @package Scout_Build
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_hub_plan' );
delete_option( 'scout_hub_update_holds' );
delete_option( 'scout_hub_log' );
delete_option( 'scout_hub_page' );
delete_transient( 'scout_hub_catalog' );
delete_transient( 'scout_hub_releases' );
delete_transient( 'scout_updater_scout-build' );
