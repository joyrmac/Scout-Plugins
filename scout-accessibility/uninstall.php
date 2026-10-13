<?php
/**
 * Remove everything this plugin stored: the settings, the check results, the
 * at-launch checklist and the weekly event. The Accessibility page is removed
 * only if it still holds nothing but the generated statement. A page someone
 * has edited is kept, so their words are never lost.
 *
 * @package Scout_Accessibility
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$scout_a11y = (array) get_option( 'scout_accessibility', array() );
$scout_page = isset( $scout_a11y['page_id'] ) ? get_post( (int) $scout_a11y['page_id'] ) : null;
if ( $scout_page instanceof WP_Post && 'page' === $scout_page->post_type ) {
	$scout_generated = "<!-- wp:shortcode -->\n[scout_accessibility_statement]\n<!-- /wp:shortcode -->";
	if ( trim( $scout_page->post_content ) === $scout_generated ) {
		wp_delete_post( $scout_page->ID, true );
	}
}

delete_option( 'scout_accessibility' );
delete_option( 'scout_accessibility_results' );
delete_option( 'scout_accessibility_launch' );
wp_clear_scheduled_hook( 'scout_accessibility_weekly' );
