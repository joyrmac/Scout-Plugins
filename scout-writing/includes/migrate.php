<?php
/**
 * Moving writing over from Scout — Bliss Boogie 0.2.x.
 *
 * That plugin kept entries as private "bb_writing" posts with "_bb_*" meta, and stars and done marks in the
 * "scout_bb_writing_marks" user meta. This renames them in place: the same post IDs, authors, dates, words,
 * voice memos (attachments keep their parent ID) and draft-post links, under this plugin's names.
 * Safe to run any number of times; each run that moves something is logged on the Writing settings screen.
 *
 * @package Scout_Writing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SCOUT_WRITING_LEGACY_CPT   = 'bb_writing';
const SCOUT_WRITING_LEGACY_MARKS = 'scout_bb_writing_marks';
/** A log of what each migration moved: array of array( 'at' => int, 'line' => string ). */
const SCOUT_WRITING_MIGRATION_LOG = 'scout_writing_migration_log';

/** Old entry meta key => new key. */
function scout_writing_legacy_meta(): array {
	$out = array();
	foreach ( array( 'prompt_id', 'step', 'destinations', 'status', 'sent_at', 'sent_mail', 'memos', 'draft_post', 'draft_at' ) as $k ) {
		$out[ '_bb_' . $k ] = '_sw_' . $k;
	}
	return $out;
}

/** Is there anything left to move? Two indexed lookups. */
function scout_writing_has_legacy(): bool {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", SCOUT_WRITING_LEGACY_CPT ) )
		|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", SCOUT_WRITING_LEGACY_MARKS ) )
		|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", '_bb_writing_entry' ) );
	// phpcs:enable
}

/**
 * Move every old entry, mark and draft link to this plugin's names.
 *
 * @return array{entries:int,people:int,drafts:int}
 */
function scout_writing_migrate(): array {
	global $wpdb;
	$moved = array( 'entries' => 0, 'people' => 0, 'drafts' => 0 );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time rename of this plugin's own rows; IDs are integers.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", SCOUT_WRITING_LEGACY_CPT ) ) );
	if ( $ids ) {
		$in = implode( ',', $ids );
		// Meta first, then the post type, so a run cut short is finished by the next one.
		foreach ( scout_writing_legacy_meta() as $old => $new ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s AND post_id IN ($in)", $new, $old ) );
		}
		$moved['entries'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s AND ID IN ($in)", SCOUT_WRITING_CPT, SCOUT_WRITING_LEGACY_CPT ) );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			wp_cache_delete( $id, 'post_meta' );
		}
	}
	// Draft posts started from an entry point back at it.
	$draft_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_bb_writing_entry' ) ) );
	if ( $draft_ids ) {
		$moved['drafts'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", '_sw_writing_entry', '_bb_writing_entry' ) );
		foreach ( $draft_ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}
	}
	// phpcs:enable
	// Stars and done marks, merged with any this plugin already holds.
	$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", SCOUT_WRITING_LEGACY_MARKS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $users as $uid ) {
		$old  = get_user_meta( $uid, SCOUT_WRITING_LEGACY_MARKS, true );
		$new  = get_user_meta( $uid, SCOUT_WRITING_MARKS, true );
		$next = array( 'star' => array(), 'done' => array() );
		foreach ( array_keys( $next ) as $k ) {
			$a          = is_array( $new ) && isset( $new[ $k ] ) && is_array( $new[ $k ] ) ? $new[ $k ] : array();
			$b          = is_array( $old ) && isset( $old[ $k ] ) && is_array( $old[ $k ] ) ? $old[ $k ] : array();
			$next[ $k ] = array_values( array_unique( array_filter( array_merge( $a, $b ), 'is_string' ) ) );
		}
		update_user_meta( $uid, SCOUT_WRITING_MARKS, $next );
		delete_user_meta( $uid, SCOUT_WRITING_LEGACY_MARKS );
		++$moved['people'];
	}
	if ( array_sum( $moved ) ) {
		$line = sprintf(
			'Moved from the Bliss Boogie writing room: %d writing %s, stars and done marks for %d %s, %d draft-post %s.',
			$moved['entries'],
			1 === $moved['entries'] ? 'entry' : 'entries',
			$moved['people'],
			1 === $moved['people'] ? 'person' : 'people',
			$moved['drafts'],
			1 === $moved['drafts'] ? 'link' : 'links'
		);
		$log   = (array) get_option( SCOUT_WRITING_MIGRATION_LOG, array() );
		$log[] = array( 'at' => time(), 'line' => $line );
		update_option( SCOUT_WRITING_MIGRATION_LOG, array_slice( $log, -20 ), false );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Scout Writing: ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the migration log, only with debug logging on.
		}
	}
	return $moved;
}

/**
 * When to move: on activation, and on any admin screen while old rows exist and the old room's code has not
 * been switched off yet (Scout — Bliss Boogie 0.2.x still active), or once after an update.
 */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() ) {
		return;
	}
	$checked = get_option( 'scout_writing_migration_checked' );
	if ( SCOUT_WRITING_VERSION === $checked && ! defined( 'SCOUT_BB_W_CPT' ) ) {
		return;
	}
	if ( scout_writing_has_legacy() ) {
		scout_writing_migrate();
	}
	update_option( 'scout_writing_migration_checked', SCOUT_WRITING_VERSION, true );
}, 1 );
