<?php
/**
 * Remove the settings on delete.
 *
 * @package Scout_Contact
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_contact' );
