<?php
/**
 * Remove the settings on delete. Saved site checks are kept: they are leads.
 * Delete them from Site checks first if they should go too.
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_sitecheck' );
