<?php
/**
 * Scout Reviews uninstall cleanup. Runs only when the plugin is deleted from
 * wp-admin, never on deactivate.
 *
 * Conservative on purpose, like Scout Core: reviews are content someone typed
 * in by hand, so they stay unless "Remove all reviews and settings when the
 * plugin is deleted" was turned on first.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always safe to drop: the cached release lookup is rebuilt on the next check.
delete_transient( 'scout_updater_scout-reviews' );

// The Google sign-in is a credential, never content: it always goes.
wp_clear_scheduled_hook( 'scout_reviews_google_sync' );
delete_transient( 'scout_reviews_google_token' );
delete_option( 'scout_reviews_google' );

$scout_reviews_settings = get_option( 'scout_reviews_settings', array() );
if ( ! is_array( $scout_reviews_settings ) || empty( $scout_reviews_settings['purge_on_uninstall'] ) ) {
	return;
}

$scout_reviews_ids = get_posts(
	array(
		'post_type'      => 'scout_review',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $scout_reviews_ids as $scout_reviews_id ) {
	wp_delete_post( $scout_reviews_id, true );
}

// The plugin is not loaded during uninstall, so the taxonomy must be registered to be queried.
register_taxonomy( 'scout_review_topic', 'scout_review' );
$scout_reviews_terms = get_terms( array( 'taxonomy' => 'scout_review_topic', 'hide_empty' => false, 'fields' => 'ids' ) );
if ( is_array( $scout_reviews_terms ) ) {
	foreach ( $scout_reviews_terms as $scout_reviews_term ) {
		wp_delete_term( (int) $scout_reviews_term, 'scout_review_topic' );
	}
}

delete_option( 'scout_reviews_settings' );
delete_option( 'scout_reviews_seen_slots' );
