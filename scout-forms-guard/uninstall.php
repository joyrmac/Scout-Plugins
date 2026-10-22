<?php
/**
 * Scout Forms Guard uninstall: the monthly blocked count and the hidden upload list.
 *
 * @package Scout_Forms_Guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_forms_guard_blocked' );
delete_transient( 'scout_forms_guard_hidden_uploads' );
