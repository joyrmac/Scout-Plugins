<?php
/**
 * Remove everything on delete: the setting, every case result (with its
 * fields and revisions) and the practice areas.
 *
 * @package Scout_Results
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scout_results' );

$scout_results_ids = get_posts(
	array(
		'post_type'      => 'scout_result',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
foreach ( $scout_results_ids as $scout_results_id ) {
	wp_delete_post( (int) $scout_results_id, true ); // Also removes its meta and revisions.
}

// The taxonomy is not registered while uninstalling, so register it briefly to delete its terms.
register_taxonomy( 'scout_result_area', 'scout_result' );
$scout_results_terms = get_terms(
	array(
		'taxonomy'   => 'scout_result_area',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $scout_results_terms ) ) {
	foreach ( $scout_results_terms as $scout_results_term ) {
		wp_delete_term( (int) $scout_results_term, 'scout_result_area' );
	}
}
