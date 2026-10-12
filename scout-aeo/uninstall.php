<?php
/**
 * Remove the settings on delete. Questions and answers stay on their pages,
 * because they are the client's content.
 *
 * @package Scout_AEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_aeo' );
