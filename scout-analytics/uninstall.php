<?php
/**
 * Remove the settings on delete. Visitors' saved choices live in their own
 * browsers and expire by themselves.
 *
 * @package Scout_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_analytics' );
