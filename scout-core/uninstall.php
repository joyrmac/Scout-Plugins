<?php
/**
 * Scout Core uninstall cleanup. Runs only when the plugin is deleted from
 * wp-admin (not on deactivate). Removes Scout's own options, its cached update
 * manifest, and the per-page SEO meta it wrote. Content types and their posts
 * are intentionally left in place, since deleting the plugin should not delete
 * the client's content.
 *
 * @package Scout_Core
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_business' );
delete_option( 'scout_seo_defaults' );
delete_transient( 'scout_core_update_manifest' );

foreach ( array( '_scout_seo_title', '_scout_seo_desc', '_scout_seo_canonical', '_scout_seo_noindex' ) as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}
