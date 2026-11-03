<?php
/**
 * Deleting the plugin removes its settings only. Member accounts and their
 * records stay, because they are the client's people: putting the plugin back
 * brings every membership back as it was. Use the WordPress privacy tools to
 * erase one person's membership record on request.
 *
 * @package Scout_Members
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_members' );
delete_option( 'scout_members_questions' );
delete_option( 'scout_members_next_step' );
// The plan-to-product links. The WooCommerce products and orders stay: they are the shop's records.
delete_option( 'scout_members_products' );
delete_option( 'scout_members_unmatched' );
delete_option( 'scout_members_version' );
wp_clear_scheduled_hook( 'scout_members_daily' );
