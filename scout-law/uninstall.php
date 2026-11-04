<?php
/**
 * Remove the settings on delete. The approval records
 * (scout_law_approval_log, scout_law_content_log, and the approval fields on
 * each page or post) stay: they are the firm's advertising record, and
 * bar rules ask firms to keep those. Delete them by hand only when the firm
 * says it no longer needs it.
 *
 * @package Scout_Law
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_law_disclaimers' );
delete_option( 'scout_law_approvals' );
