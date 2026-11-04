<?php
/**
 * Scout Build uninstall: settings and caches only. Change requests and the
 * Site log are the client's own record, so they stay.
 *
 * @package Scout_Build
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_hub_plan' );
delete_option( 'scout_hub_update_holds' );
delete_option( 'scout_hub_log' );
delete_option( 'scout_hub_answer_seen' );
delete_option( 'scout_hub_page' );
delete_option( 'scout_hub_hidden_alert_sent' );
delete_option( 'scout_hub_log_imported' );
delete_option( 'scout_hub_log_keys' );
delete_option( 'scout_hub_report_settings' );
wp_clear_scheduled_hook( 'scout_hub_report_daily' );
wp_clear_scheduled_hook( 'scout_hub_daily_watch' );
delete_transient( 'scout_hub_catalog' );
delete_transient( 'scout_hub_releases' );
delete_transient( 'scout_updater_scout-build' );
