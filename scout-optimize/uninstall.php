<?php
/**
 * Uninstall routine for Scout Optimize.
 *
 * Removes plugin options and the gate capability. DELIBERATELY does not touch
 * any media files: optimized images, WebP twins, and preserved originals all
 * remain on disk. Destroying media on uninstall would violate the project's
 * one-way-door invariant (vision doc §3.4) — a site owner uninstalling the
 * plugin must never lose images.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_optimize_version' );
delete_option( 'scout_optimize_settings' );

$scout_optimize_role = get_role( 'administrator' );
if ( $scout_optimize_role ) {
	$scout_optimize_role->remove_cap( 'manage_scout_optimize' );
}
