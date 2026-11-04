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
delete_option( 'scout_hub_plan_picks' );
delete_option( 'scout_hub_recommended' );
delete_option( 'scout_hub_setup_hidden' );
wp_clear_scheduled_hook( 'scout_hub_inbox_digest' );
foreach ( array( 'scout_hub_today_snoozed', 'scout_hub_today_seen', 'scout_hub_inbox_email', 'scout_hub_inbox_email_sent' ) as $scout_hub_key ) {
	delete_metadata( 'user', 0, $scout_hub_key, '', true );
}
delete_option( 'scout_hub_update_holds' );
delete_option( 'scout_hub_log' );
delete_option( 'scout_hub_answer_seen' );
delete_option( 'scout_hub_page' );
delete_option( 'scout_hub_hidden_alert_sent' );
delete_option( 'scout_hub_log_imported' );
delete_option( 'scout_hub_log_keys' );
wp_clear_scheduled_hook( 'scout_hub_daily_watch' );
delete_transient( 'scout_hub_catalog' );
delete_transient( 'scout_hub_releases' );
delete_transient( 'scout_updater_scout-build' );
