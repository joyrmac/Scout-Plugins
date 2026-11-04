<?php
/**
 * Remove the disclaimer settings on delete. The approval record
 * (scout_law_approval_log) stays: it is the firm's advertising record, and
 * bar rules ask firms to keep those. Delete it by hand only when the firm
 * says it no longer needs it.
 *
 * @package Scout_Law
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'scout_law_disclaimers' );
