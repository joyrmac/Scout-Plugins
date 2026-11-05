<?php
/**
 * The writing room: a client's writing plan inside wp-admin, so they write where they log in.
 *
 * Built for Kai Baylis (Bliss Boogie) in Scout — Bliss Boogie 0.2.0 to 0.2.3 and moved here so every Scout
 * client can have one (Joy, 2026-11-03: "yes move the writing room into a scout plugin").
 *
 * - Your Writing (edit_posts): the plan's steps and prompts from the theme's prompts file (includes/config.php).
 *   Search, filters, Star, Write, Mark done.
 * - Write: one private scout_writing entry per prompt per user. Autosaves through admin-ajax with a nonce. Works
 *   without JavaScript through a plain form (admin-post).
 * - Send to Scout: status "ready" and an email to Scout with an admin link (no public link).
 * - Start a draft from this (website or newsletter prompts): a normal WordPress draft post, never published.
 * - All writing (manage_options): every entry, with a "Sent to Scout" view.
 * - A welcome panel at the top of the dashboard with one suggested prompt and progress.
 *
 * Entries are never public: not queryable, not searchable, not in REST, not in sitemaps, status private.
 *
 * @package Scout_Writing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SCOUT_WRITING_CPT   = 'scout_writing';
const SCOUT_WRITING_PAGE  = 'scout-writing';
/** The room's address in Scout — Bliss Boogie 0.2.x. Links to it (Kai's writing plan) still open the room. */
const SCOUT_WRITING_OLD_PAGE = 'scout-bb-writing';
const SCOUT_WRITING_NONCE = 'scout_writing';
/** User meta: array( 'star' => string[], 'done' => string[] ) of prompt ids. */
const SCOUT_WRITING_MARKS = 'scout_writing_marks';
/** Longest answer kept, in characters (about 30,000 words). */
const SCOUT_WRITING_MAX = 200000;

/* ------------------------------------------------------------------ data */

/**
 * The plan: steps, prompts, blocks, reference lists. Read once per request.
 *
 * @return array{steps:array,prompts:array,by_id:array,dest:array,time:array,groups:array,blocks:array,social_rules:array,guests:array,inventory:array}
 */
/** Who writing goes to: Scout on Scout-branded sites, "your web team" on white-label sites. */
function scout_writing_team(): string {
	$branded = class_exists( 'Scout_Admin_UI' ) && method_exists( 'Scout_Admin_UI', 'branded' ) ? Scout_Admin_UI::branded() : true;
	return $branded ? 'Scout' : 'your web team';
}

function scout_writing_data(): array {
	static $data = null;
	if ( null !== $data ) {
		return $data;
	}
	$data          = scout_writing_parse( scout_writing_source()['json'] );
	$data['by_id'] = array();
	foreach ( $data['prompts'] as $p ) {
		$data['by_id'][ $p['id'] ] = $p;
	}
	$data['dest_labels'] = wp_list_pluck( array_filter( $data['dest'], 'is_array' ), 'label', 'key' );
	$data['time_labels'] = wp_list_pluck( array_filter( $data['time'], 'is_array' ), 'label', 'key' );
	return $data;
}

/** Are there prompts to show? */
function scout_writing_has_prompts(): bool {
	return (bool) scout_writing_data()['prompts'];
}

/** Can this person open the room? Writers when there are prompts; administrators always (to set it up). */
function scout_writing_can_see(): bool {
	return current_user_can( 'edit_posts' ) && ( scout_writing_has_prompts() || current_user_can( 'manage_options' ) );
}

/** One prompt by id, or null. */
function scout_writing_prompt( string $id ): ?array {
	$data = scout_writing_data();
	return $data['by_id'][ $id ] ?? null;
}

/** Prompt id from a request value (ids look like "important-07"). */
function scout_writing_clean_id( $raw ): string {
	return is_string( $raw ) ? substr( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $raw ) ), 0, 40 ) : '';
}

/** The step's name, e.g. "The book" or "Blog and search". */
function scout_writing_step_title( int $n ): string {
	foreach ( scout_writing_data()['steps'] as $st ) {
		if ( (int) $st['n'] === $n ) {
			return (string) $st['title'];
		}
	}
	return '';
}

/** Destination labels for a prompt. */
function scout_writing_dest_labels( array $prompt ): array {
	$labels = scout_writing_data()['dest_labels'];
	return array_values( array_filter( array_map( static fn( $d ) => $labels[ $d ] ?? '', (array) $prompt['dest'] ) ) );
}

/** A "Start a draft from this" is offered for website and newsletter prompts. */
function scout_writing_can_draft( array $prompt ): bool {
	return (bool) array_intersect( array( 'site', 'news' ), (array) $prompt['dest'] );
}

/* ------------------------------------------------------------------ marks (user meta) */

/** @return array{star:string[],done:string[]} */
function scout_writing_marks( int $user_id ): array {
	$raw   = get_user_meta( $user_id, SCOUT_WRITING_MARKS, true );
	$known = scout_writing_data()['by_id'];
	$out   = array( 'star' => array(), 'done' => array() );
	foreach ( array_keys( $out ) as $k ) {
		$list      = is_array( $raw ) && isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? $raw[ $k ] : array();
		$out[ $k ] = array_values( array_unique( array_filter( $list, static fn( $id ) => is_string( $id ) && isset( $known[ $id ] ) ) ) );
	}
	return $out;
}

/** Turn a star or done mark on or off. Done also moves the entry's status. */
function scout_writing_set_mark( int $user_id, string $pid, string $mark, bool $on ): array {
	$marks = scout_writing_marks( $user_id );
	if ( ! isset( $marks[ $mark ] ) ) {
		return $marks;
	}
	$list = array_diff( $marks[ $mark ], array( $pid ) );
	if ( $on ) {
		$list[] = $pid;
	}
	$marks[ $mark ] = array_values( $list );
	update_user_meta( $user_id, SCOUT_WRITING_MARKS, $marks );
	if ( 'done' === $mark ) {
		$entry = scout_writing_entry( $user_id, $pid );
		if ( $entry ) {
			$back = get_post_meta( $entry->ID, '_sw_sent_at', true ) ? 'ready' : 'draft';
			update_post_meta( $entry->ID, '_sw_status', $on ? 'done' : $back );
		}
	}
	return $marks;
}

/* ------------------------------------------------------------------ entries (private posts) */

add_action( 'init', function () {
	$admin = 'manage_options';
	register_post_type(
		SCOUT_WRITING_CPT,
		array(
			'labels'              => array(
				'name'          => 'Writing',
				'singular_name' => 'Writing entry',
				'menu_name'     => 'All writing',
				'all_items'     => 'All writing',
				'edit_item'     => 'Writing entry',
				'search_items'  => 'Search writing',
				'not_found'     => 'Nothing written yet.',
			),
			'description'         => 'Answers written in the writing room. Private: never shown on the site.',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'supports'            => array( 'title', 'editor', 'author' ),
			'map_meta_cap'        => true,
			// The list and edit screens are Scout's view (administrators). Writers use the room, which checks
			// that the entry is theirs. Nobody adds an entry by hand: every entry belongs to a prompt.
			'capabilities'        => array(
				'edit_posts'             => $admin,
				'edit_others_posts'      => $admin,
				'edit_private_posts'     => $admin,
				'edit_published_posts'   => $admin,
				'publish_posts'          => $admin,
				'read_private_posts'     => $admin,
				'delete_posts'           => $admin,
				'delete_private_posts'   => $admin,
				'delete_published_posts' => $admin,
				'delete_others_posts'    => $admin,
				'create_posts'           => 'do_not_allow',
			),
		)
	);
} );

/** The user's entry for a prompt, or null. */
function scout_writing_entry( int $user_id, string $pid ): ?WP_Post {
	$found = get_posts(
		array(
			'post_type'        => SCOUT_WRITING_CPT,
			'post_status'      => array( 'private', 'draft', 'publish', 'pending' ),
			'author'           => $user_id,
			'meta_key'         => '_sw_prompt_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => $pid, // phpcs:ignore WordPress.DB.SlowDBQuery
			'numberposts'      => 1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);
	return $found ? $found[0] : null;
}

/** Every entry the user has, newest edit first. @return WP_Post[] */
function scout_writing_entries( int $user_id ): array {
	return get_posts(
		array(
			'post_type'        => SCOUT_WRITING_CPT,
			'post_status'      => array( 'private', 'draft', 'publish', 'pending' ),
			'author'           => $user_id,
			'numberposts'      => -1,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
		)
	);
}

/**
 * Her words as plain text. Stored escaped, so the post content is safe in the editor whatever she types
 * ("<3" included), and decoded on the way back into her textarea.
 */
function scout_writing_text( WP_Post $entry ): string {
	return html_entity_decode( (string) $entry->post_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/** Clean text from a request: valid UTF-8, no NUL bytes, normal line ends, capped. */
function scout_writing_clean_text( $raw ): string {
	$text = is_string( $raw ) ? wp_unslash( $raw ) : '';
	$text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), wp_check_invalid_utf8( $text, true ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, SCOUT_WRITING_MAX ) : substr( $text, 0, SCOUT_WRITING_MAX );
}

/** Create the entry if it is missing. */
function scout_writing_ensure_entry( int $user_id, string $pid ) {
	$entry = scout_writing_entry( $user_id, $pid );
	if ( $entry ) {
		return $entry;
	}
	$prompt = scout_writing_prompt( $pid );
	$id     = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => SCOUT_WRITING_CPT,
				'post_status'  => 'private',
				'post_author'  => $user_id,
				'post_title'   => $prompt['q'],
				'post_content' => '',
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_post_meta( $id, '_sw_prompt_id', $pid );
	update_post_meta( $id, '_sw_step', (int) $prompt['step'] );
	update_post_meta( $id, '_sw_destinations', implode( ' ', (array) $prompt['dest'] ) );
	$done = in_array( $pid, scout_writing_marks( $user_id )['done'], true );
	update_post_meta( $id, '_sw_status', $done ? 'done' : 'draft' );
	return get_post( $id );
}

/** Save her answer. An empty answer on a prompt never written creates nothing. */
function scout_writing_save( int $user_id, string $pid, string $text ) {
	$entry = scout_writing_entry( $user_id, $pid );
	if ( ! $entry && '' === trim( $text ) ) {
		return null;
	}
	$entry = $entry ? $entry : scout_writing_ensure_entry( $user_id, $pid );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	if ( scout_writing_text( $entry ) === $text ) {
		return $entry;
	}
	$ok = wp_update_post(
		wp_slash(
			array(
				'ID'           => $entry->ID,
				'post_content' => esc_html( $text ),
				'post_status'  => 'private',
			)
		),
		true
	);
	return is_wp_error( $ok ) ? $ok : get_post( $entry->ID );
}

/** Status of an entry: draft, ready (sent to Scout) or done. */
function scout_writing_status( WP_Post $entry ): string {
	$s = (string) get_post_meta( $entry->ID, '_sw_status', true );
	return in_array( $s, array( 'draft', 'ready', 'done' ), true ) ? $s : 'draft';
}

function scout_writing_status_label( string $status ): string {
	return array(
		'draft' => 'Draft',
		'ready' => 'Sent to ' . scout_writing_team(),
		'done'  => 'Done',
	)[ $status ] ?? 'Draft';
}

/** Voice memo attachment ids on an entry (only ones that still exist). */
function scout_writing_memos( WP_Post $entry ): array {
	$ids = array_map( 'intval', (array) get_post_meta( $entry->ID, '_sw_memos', true ) );
	return array_values( array_filter( $ids, static fn( $id ) => $id > 0 && 'attachment' === get_post_type( $id ) ) );
}

/** Words in a piece of text, counting any script. */
function scout_writing_words( string $text ): int {
	return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text );
}

/** The first words of her text, as typed (wp_trim_words would drop anything after a "<"). */
function scout_writing_excerpt( string $text, int $words ): string {
	$parts = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	return implode( ' ', array_slice( $parts, 0, $words ) ) . ( count( $parts ) > $words ? ' ...' : '' );
}

/** Mark the entry ready and email Scout. */
function scout_writing_send( int $user_id, WP_Post $entry ): bool {
	$prompt = scout_writing_prompt( (string) get_post_meta( $entry->ID, '_sw_prompt_id', true ) );
	$user   = get_userdata( $user_id );
	$name   = $user ? $user->display_name : 'Someone';
	$text   = scout_writing_text( $entry );
	update_post_meta( $entry->ID, '_sw_sent_at', time() );
	update_post_meta( $entry->ID, '_sw_status', 'ready' );
	wp_update_post( array( 'ID' => $entry->ID ) ); // Bumps "last edited".

	/**
	 * Who gets "Send to Scout" emails. The Bliss Boogie name of this filter still works.
	 *
	 * @param string[] $to Email addresses (Writing settings, the theme's send_to, or team@scoutraleigh.com).
	 */
	$to   = (array) apply_filters( 'scout_writing_recipients', apply_filters( 'scout_bb_writing_recipients', scout_writing_recipients() ) );
	$memo = count( scout_writing_memos( $entry ) );
	$lines = array(
		$name . ' sent this from the writing room on ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '.',
		'',
		'Prompt (Step ' . (int) $prompt['step'] . ', ' . $prompt['group'] . '):',
		$prompt['q'],
		'',
		'Where it could go: ' . implode( ', ', scout_writing_dest_labels( $prompt ) ),
		'Words: ' . scout_writing_words( $text ) . ( $memo ? '   Voice memos: ' . $memo : '' ),
		'',
		'Excerpt:',
		'' !== trim( $text ) ? scout_writing_excerpt( $text, 60 ) : '(No text yet. See the voice memo.)',
		'',
		'Open the entry (sign in first):',
		admin_url( 'post.php?post=' . $entry->ID . '&action=edit' ),
	);
	$headers = array();
	if ( $user && is_email( $user->user_email ) ) {
		$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '"', '<', '>' ), '', $name ) . ' <' . $user->user_email . '>';
	}
	$subject = $name . ' sent writing to ' . scout_writing_team() . ': ' . wp_trim_words( $prompt['q'], 9, '...' );
	$sent    = wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
	update_post_meta( $entry->ID, '_sw_sent_mail', $sent ? '1' : '0' );
	return (bool) $sent;
}

/** Start a normal WordPress draft post from the entry, once. Never published. */
function scout_writing_start_draft( int $user_id, WP_Post $entry ) {
	$existing = (int) get_post_meta( $entry->ID, '_sw_draft_post', true );
	if ( $existing && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
		return $existing;
	}
	$prompt = scout_writing_prompt( (string) get_post_meta( $entry->ID, '_sw_prompt_id', true ) );
	$blocks = '';
	foreach ( preg_split( "/\n\s*\n/", trim( scout_writing_text( $entry ) ) ) as $para ) {
		if ( '' !== trim( $para ) ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( trim( $para ) ), false ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_title'   => $prompt['q'],
				'post_content' => $blocks,
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_post_meta( $id, '_sw_writing_entry', $entry->ID );
	update_post_meta( $entry->ID, '_sw_draft_post', $id );
	update_post_meta( $entry->ID, '_sw_draft_at', time() );
	return $id;
}

/* ------------------------------------------------------------------ actions (ajax + no-JS) */

/**
 * Everything the room needs to show one prompt's entry.
 */
function scout_writing_payload( int $user_id, string $pid, string $note = '' ): array {
	$prompt = scout_writing_prompt( $pid );
	$entry  = scout_writing_entry( $user_id, $pid );
	$marks  = scout_writing_marks( $user_id );
	$out    = array(
		'pid'      => $pid,
		'id'       => 0,
		'text'     => '',
		'status'   => '',
		'label'    => '',
		'saved'    => '',
		'sent'     => '',
		'memos'    => array(),
		'draft'    => null,
		'canDraft' => $prompt ? scout_writing_can_draft( $prompt ) : false,
		'star'     => in_array( $pid, $marks['star'], true ),
		'done'     => in_array( $pid, $marks['done'], true ),
		'doneN'    => count( $marks['done'] ),
		'note'     => $note,
	);
	if ( ! $entry ) {
		return $out;
	}
	$status        = scout_writing_status( $entry );
	$out['id']     = $entry->ID;
	$out['text']   = scout_writing_text( $entry );
	$out['status'] = $status;
	$out['label']  = scout_writing_status_label( $status );
	$out['saved']  = 'Saved ' . wp_date( 'M j, g:i a', strtotime( $entry->post_modified_gmt . ' UTC' ) );
	$sent_at       = (int) get_post_meta( $entry->ID, '_sw_sent_at', true );
	$out['sent']   = $sent_at ? 'Sent to ' . scout_writing_team() . ' on ' . wp_date( 'F j', $sent_at ) . '.' : '';
	foreach ( scout_writing_memos( $entry ) as $att ) {
		$mime           = (string) get_post_mime_type( $att );
		$out['memos'][] = array(
			'id'    => $att,
			'title' => get_the_title( $att ),
			'url'   => wp_get_attachment_url( $att ),
			'audio' => 0 === strpos( $mime, 'audio/' ),
		);
	}
	$draft = (int) get_post_meta( $entry->ID, '_sw_draft_post', true );
	if ( $draft && get_post( $draft ) && 'trash' !== get_post_status( $draft ) ) {
		$out['draft'] = array(
			'id'   => $draft,
			'url'  => current_user_can( 'edit_post', $draft ) ? get_edit_post_link( $draft, 'raw' ) : '',
			'when' => wp_date( 'F j', (int) get_post_meta( $entry->ID, '_sw_draft_at', true ) ),
		);
	}
	return $out;
}

/**
 * Run one action for the signed-in user. Returns the prompt's payload or an error.
 *
 * @param string $do  get | save | mark | send | draft | memo | unmemo
 * @param array  $req The request (unslashed values are cleaned here).
 */
function scout_writing_do( string $do, array $req ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'sw_cap', 'Sign in again to keep writing.' );
	}
	$pid    = scout_writing_clean_id( $req['pid'] ?? '' );
	$prompt = scout_writing_prompt( $pid );
	if ( ! $prompt ) {
		return new WP_Error( 'sw_prompt', 'That prompt is not in your plan.' );
	}
	$note = '';
	switch ( $do ) {
		case 'get':
			break;
		case 'save':
			$res = scout_writing_save( $user_id, $pid, scout_writing_clean_text( $req['text'] ?? '' ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			break;
		case 'mark':
			$mark = in_array( $req['mark'] ?? '', array( 'star', 'done' ), true ) ? $req['mark'] : '';
			if ( ! $mark ) {
				return new WP_Error( 'sw_mark', 'Unknown mark.' );
			}
			$marks = scout_writing_marks( $user_id );
			$on    = isset( $req['on'] ) ? '1' === (string) $req['on'] : ! in_array( $pid, $marks[ $mark ], true );
			scout_writing_set_mark( $user_id, $pid, $mark, $on );
			break;
		case 'send':
			if ( isset( $req['text'] ) ) {
				$res = scout_writing_save( $user_id, $pid, scout_writing_clean_text( $req['text'] ) );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
			$entry = scout_writing_entry( $user_id, $pid );
			if ( ! $entry || ( '' === trim( scout_writing_text( $entry ) ) && ! scout_writing_memos( $entry ) ) ) {
				return new WP_Error( 'sw_empty', 'Write a little or add a voice memo first, then send it.' );
			}
			$note = scout_writing_send( $user_id, $entry )
				? 'Sent. ' . ucfirst( scout_writing_team() ) . ' has it now. You can keep adding to it, and send again any time.'
				: 'Marked sent, but the email did not go out from this site. Let ' . scout_writing_team() . ' know.';
			break;
		case 'draft':
			if ( ! scout_writing_can_draft( $prompt ) ) {
				return new WP_Error( 'sw_draft', 'Drafts are for website and newsletter prompts.' );
			}
			if ( isset( $req['text'] ) ) {
				scout_writing_save( $user_id, $pid, scout_writing_clean_text( $req['text'] ) );
			}
			$entry = scout_writing_entry( $user_id, $pid );
			if ( ! $entry || '' === trim( scout_writing_text( $entry ) ) ) {
				return new WP_Error( 'sw_empty', 'Write a little first, then start a draft from it.' );
			}
			$res = scout_writing_start_draft( $user_id, $entry );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$note = 'A draft post is waiting in Posts for ' . scout_writing_team() . ' to shape. It stays a draft, so nothing goes live.';
			break;
		case 'memo':
		case 'unmemo':
			$att = absint( $req['att'] ?? 0 );
			if ( ! $att || 'attachment' !== get_post_type( $att ) || ! current_user_can( 'edit_post', $att ) ) {
				return new WP_Error( 'sw_att', 'That file could not be attached.' );
			}
			$entry = 'memo' === $do ? scout_writing_ensure_entry( $user_id, $pid ) : scout_writing_entry( $user_id, $pid );
			if ( is_wp_error( $entry ) || ! $entry ) {
				return is_wp_error( $entry ) ? $entry : new WP_Error( 'sw_entry', 'Nothing to change.' );
			}
			$memos = array_diff( scout_writing_memos( $entry ), array( $att ) );
			if ( 'memo' === $do ) {
				$memos[] = $att;
				// Attached to the private entry, so the file's own page is not public either.
				if ( 0 === (int) get_post_field( 'post_parent', $att ) ) {
					wp_update_post( array( 'ID' => $att, 'post_parent' => $entry->ID ) );
				}
			}
			update_post_meta( $entry->ID, '_sw_memos', array_values( $memos ) );
			wp_update_post( array( 'ID' => $entry->ID ) );
			break;
		default:
			return new WP_Error( 'sw_do', 'Unknown action.' );
	}
	return scout_writing_payload( $user_id, $pid, $note );
}

add_action( 'wp_ajax_scout_writing', function () {
	if ( ! check_ajax_referer( SCOUT_WRITING_NONCE, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'This page has been open a long time. Reload it to keep writing (your words are safe up to the last "Saved").' ), 403 );
	}
	$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$res = scout_writing_do( $do, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
	}
	wp_send_json_success( $res );
} );

/** Without JavaScript: the same actions through admin-post, then back to the page. */
add_action( 'admin_post_scout_writing', function () {
	check_admin_referer( SCOUT_WRITING_NONCE );
	$do  = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
	$req = array_merge( $_GET, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
	$res = scout_writing_do( $do, $req );
	$pid = scout_writing_clean_id( $req['pid'] ?? '' );
	$args = array( 'page' => SCOUT_WRITING_PAGE );
	if ( ! empty( $req['back'] ) && 'write' === $req['back'] ) {
		$args['write'] = $pid;
	}
	if ( is_wp_error( $res ) ) {
		$args['sw_err'] = $res->get_error_code();
	} elseif ( '' !== $res['note'] || 'save' === $do ) {
		$args['sw_msg'] = $do;
	}
	$url = add_query_arg( $args, admin_url( 'admin.php' ) );
	wp_safe_redirect( $url . ( empty( $args['write'] ) && $pid ? '#sw-p-' . $pid : '' ) );
	exit;
} );

/* ------------------------------------------------------------------ menu */

/** The room's address, optionally on one prompt. */
function scout_writing_url( string $pid = '' ): string {
	$args = array( 'page' => SCOUT_WRITING_PAGE );
	if ( $pid ) {
		$args['write'] = $pid;
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/** The site's Build section (Scout Build, Recon Build, Site Build or Scout), or '' when it has none. */
function scout_writing_build_parent(): string {
	global $admin_page_hooks;
	foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
		if ( isset( $admin_page_hooks[ $slug ] ) ) {
			return $slug;
		}
	}
	return '';
}

/** Runs after Scout Core, the Scout Build hub (15) and the other features, so the Build section exists. */
add_action( 'admin_menu', function () {
	if ( ! scout_writing_can_see() ) {
		return; // No prompts: writers see nothing; administrators get the "No prompts yet" screen.
	}
	$list   = 'edit.php?post_type=' . SCOUT_WRITING_CPT;
	$label  = scout_writing_menu_label();
	// Its own top-level section, right under Dashboard, so the writer sees it first (Joy, 2026-11-03: "writing should be
	// an ENTIRELY NEW section... make it stand out... VERY CLEAR for her"). A named exception to Scout's "client tools
	// live under the Scout menu" rule. Writing room is a Plus module, so its administrators' screens live in this
	// section too (dashboard redesign, Joy 2026-11-04: Plus modules get their own sections completely).
	$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M15.6 2.2a1.6 1.6 0 0 1 2.2 2.2l-9.6 9.6-3.1.9.9-3.1zM3 16.5h14v1.5H3z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	$hook = add_menu_page( $label, $label, 'edit_posts', SCOUT_WRITING_PAGE, 'scout_writing_render', $icon, 2.5 );
	add_submenu_page( SCOUT_WRITING_PAGE, $label, 'Write', 'edit_posts', SCOUT_WRITING_PAGE );
	add_submenu_page( SCOUT_WRITING_PAGE, 'All writing', 'All writing', 'manage_options', $list );
	$settings = add_submenu_page( SCOUT_WRITING_PAGE, 'Settings', 'Settings', 'manage_options', SCOUT_WRITING_SETTINGS_PAGE, 'scout_writing_settings_render' );
	add_action( 'load-' . $hook, 'scout_writing_assets_hook' );
	if ( $settings ) {
		add_action( 'load-' . $settings, 'scout_writing_settings_assets_hook' );
	}
}, 31 );

/**
 * The room's old address (Scout — Bliss Boogie 0.2.x, admin.php?page=scout-bb-writing&write=<id>) opens the room.
 * WordPress refuses an unknown page before admin_init, so this listens for that refusal.
 */
add_action( 'admin_page_access_denied', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a redirect only.
	if ( SCOUT_WRITING_OLD_PAGE !== $page || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$args = array( 'page' => SCOUT_WRITING_PAGE );
	foreach ( array( 'write', 'view' ) as $k ) {
		if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args[ $k ] = 'write' === $k ? scout_writing_clean_id( wp_unslash( $_GET[ $k ] ) ) : sanitize_key( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 301 );
	exit;
} );

/** The menu item stands out on every admin screen: the brand's accent, a highlight edge, a small "New" tag. */
add_action( 'admin_head', function () {
	if ( ! scout_writing_can_see() ) {
		return;
	}
	$b   = scout_writing_brand();
	$id  = '#toplevel_page_' . SCOUT_WRITING_PAGE;
	$tag = scout_writing_menu_tag();
	echo '<style>'
		. $id . ' > a{background:' . $b['accent'] . '!important;color:' . $b['on_accent'] . '!important;font-weight:600;border-left:4px solid ' . $b['highlight'] . ';margin:6px 0}'
		. $id . ' > a .wp-menu-image:before,' . $id . ' > a .wp-menu-image img{opacity:1!important}'
		. $id . ' > a .wp-menu-image{filter:brightness(0) invert(1)}'
		. $id . ' > a:hover,' . $id . '.current > a,' . $id . '.wp-has-current-submenu > a{background:' . $b['accent_dark'] . '!important;color:' . $b['on_accent'] . '!important}'
		. ( '' !== $tag ? $id . ' .wp-menu-name:after{content:"' . esc_attr( $tag ) . '";display:inline-block;margin-left:6px;padding:1px 6px;border-radius:9px;background:' . $b['highlight'] . ';color:' . $b['ink'] . ';font-size:10px;font-weight:700;letter-spacing:.04em;vertical-align:1px}' : '' )
		. '#wpadminbar #wp-admin-bar-scout-writing > .ab-item{background:' . $b['accent'] . ';color:' . $b['on_accent'] . '}'
		. '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- colors pass sanitize_hex_color.
} );

/** A "Your Writing" button in the top toolbar, on every screen and on the site itself when signed in. */
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ! is_user_logged_in() || ! scout_writing_has_prompts() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$bar->add_node( array( 'id' => 'scout-writing', 'title' => '✦ ' . esc_html( scout_writing_menu_label() ), 'href' => scout_writing_url() ) );
}, 31 );

/** All writing stays highlighted on the list and on an entry. */
add_filter( 'parent_file', function ( $parent ) {
	global $submenu_file;
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && SCOUT_WRITING_CPT === $screen->post_type ) {
		$submenu_file = 'edit.php?post_type=' . SCOUT_WRITING_CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		return SCOUT_WRITING_PAGE;
	}
	return $parent;
} );

/* ------------------------------------------------------------------ assets */

function scout_writing_assets_hook() {
	add_action( 'admin_enqueue_scripts', 'scout_writing_assets' );
}

function scout_writing_assets() {
	$base = plugins_url( 'assets/', SCOUT_WRITING_FILE );
	// The room sits in the Scout admin frame: the kit first, then the room's own styles on its tokens.
	Scout_Admin_UI::enqueue( $base . 'scout-admin.css' );
	wp_enqueue_style( 'scout-writing', $base . 'writing-room.css', array( 'scout-admin' ), SCOUT_WRITING_VERSION );
	// The brand from the theme's setup file, and its own fonts, when the theme sets them; Scout's look otherwise.
	$brand = scout_writing_brand()['font_faces'] . scout_writing_brand_css( '.sw' );
	if ( '' !== $brand ) {
		wp_add_inline_style( 'scout-writing', $brand );
	}
	if ( ! scout_writing_has_prompts() ) {
		return;
	}
	$can_memo = current_user_can( 'upload_files' );
	if ( $can_memo ) {
		wp_enqueue_media();
	}
	wp_enqueue_script( 'scout-writing', $base . 'writing-room.js', $can_memo ? array( 'media-editor' ) : array(), SCOUT_WRITING_VERSION, true );
	wp_add_inline_script(
		'scout-writing',
		'window.scoutWriting = ' . wp_json_encode(
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( SCOUT_WRITING_NONCE ),
				'canMemo' => $can_memo,
				'total'   => count( scout_writing_data()['prompts'] ),
			)
		) . ';',
		'before'
	);
}

/* ------------------------------------------------------------------ the room */

/** "Most <em>important</em>" from a step or block title. */
function scout_writing_title_html( string $lead, string $accent, string $tail = '' ): string {
	if ( '' === $accent ) {
		return esc_html( trim( $lead . $tail ) );
	}
	return ( '' !== $lead ? esc_html( $lead ) . ' ' : '' ) . '<em>' . esc_html( $accent ) . '</em>' . esc_html( $tail );
}

/** Mark link (works without JavaScript; the script turns it into a toggle). */
function scout_writing_mark_url( string $pid, string $mark ): string {
	return wp_nonce_url( add_query_arg( array( 'action' => 'scout_writing', 'do' => 'mark', 'mark' => $mark, 'pid' => $pid ), admin_url( 'admin-post.php' ) ), SCOUT_WRITING_NONCE );
}

/** One prompt in the list. */
function scout_writing_prompt_html( array $p, array $marks, array $entries ): string {
	$d       = scout_writing_data();
	$star    = in_array( $p['id'], $marks['star'], true );
	$done    = in_array( $p['id'], $marks['done'], true );
	$entry   = $entries[ $p['id'] ] ?? null;
	$labels  = scout_writing_dest_labels( $p );
	$search  = strtolower( $p['q'] . ' ' . $p['why'] . ' ' . $p['group'] . ' ' . implode( ' ', $labels ) );
	$chips   = '';
	foreach ( (array) $p['dest'] as $k ) {
		$chips .= '<span class="sw-chip sw-d-' . esc_attr( $k ) . '">' . esc_html( $d['dest_labels'][ $k ] ?? $k ) . '</span>';
	}
	$status = $entry ? $entry['status'] : '';
	$h  = '<li class="sw-p' . ( $done ? ' is-done' : '' ) . ( $star ? ' is-star' : '' ) . '" id="sw-p-' . esc_attr( $p['id'] ) . '" data-pid="' . esc_attr( $p['id'] ) . '" data-step="' . (int) $p['step'] . '" data-dest="' . esc_attr( implode( ' ', (array) $p['dest'] ) ) . '" data-time="' . esc_attr( $p['t'] ) . '" data-s="' . esc_attr( $search ) . '">';
	$h .= '<p class="sw-q">' . esc_html( $p['q'] ) . '</p>';
	$h .= '<p class="sw-why">' . esc_html( $p['why'] ) . '</p>';
	$h .= '<div class="sw-row"><div class="sw-meta">' . $chips . ( isset( $d['time_labels'][ $p['t'] ] ) ? '<span class="sw-time">' . esc_html( $d['time_labels'][ $p['t'] ] ) . '</span>' : '' );
	$h .= '<span class="sw-status sw-s-' . esc_attr( $status ) . '"' . ( $status ? '' : ' hidden' ) . '>' . esc_html( $status ? scout_writing_status_label( $status ) : '' ) . '</span></div>';
	$h .= '<div class="sw-acts">';
	$h .= '<a class="sw-act sw-star" role="button" data-act="star" aria-pressed="' . ( $star ? 'true' : 'false' ) . '" href="' . esc_url( scout_writing_mark_url( $p['id'], 'star' ) ) . '"><span class="sw-ic" aria-hidden="true">' . ( $star ? '&#9733;' : '&#9734;' ) . '</span><span class="sw-lb">' . ( $star ? 'Starred' : 'Star' ) . '</span></a>';
	$h .= '<a class="sw-act sw-write" data-act="write" aria-expanded="false" aria-controls="sw-slot-' . esc_attr( $p['id'] ) . '" href="' . esc_url( scout_writing_url( $p['id'] ) ) . '"><span class="sw-lb">' . ( $entry ? 'Keep writing' : 'Write' ) . '</span></a>';
	$h .= '<a class="sw-act sw-done" role="button" data-act="done" aria-pressed="' . ( $done ? 'true' : 'false' ) . '" href="' . esc_url( scout_writing_mark_url( $p['id'], 'done' ) ) . '"><span class="sw-ic" aria-hidden="true">' . ( $done ? '&#10003;' : '&#9675;' ) . '</span><span class="sw-lb">' . ( $done ? 'Done' : 'Mark done' ) . '</span></a>';
	$h .= '</div></div><div class="sw-slot" id="sw-slot-' . esc_attr( $p['id'] ) . '"></div></li>';
	return $h;
}

/** A titled group of prompts. */
function scout_writing_group_html( int $step, string $title, array $prompts, array $marks, array $entries, string $tag = 'h3' ): string {
	$g     = scout_writing_data()['groups'][ $step . '|' . $title ] ?? array();
	$items = '';
	foreach ( $prompts as $p ) {
		$items .= scout_writing_prompt_html( $p, $marks, $entries );
	}
	if ( ! empty( $g['issue'] ) ) {
		$i = wp_parse_args( (array) $g['issue'], array( 'month' => '', 'theme' => '', 'send' => '', 'job' => '', 'job_label' => 'The one job' ) );
		return '<section class="sw-grp sw-issue"><p class="sw-imonth">' . esc_html( $i['month'] ) . '</p><h4 class="sw-itheme">' . esc_html( $i['theme'] ) . '</h4>'
			. '<p class="sw-isend">' . esc_html( $i['send'] ) . '</p><p class="sw-ijob"><span>' . esc_html( $i['job_label'] ) . '</span>' . esc_html( $i['job'] ) . '</p><ol class="sw-plist">' . $items . '</ol></section>';
	}
	$intro = ! empty( $g['intro'] ) ? '<p class="sw-gintro">' . esc_html( $g['intro'] ) . '</p>' : '';
	$head  = '' !== trim( $title ) ? '<' . $tag . ' class="sw-gh">' . esc_html( $title ) . '</' . $tag . '>' : '';
	return '<section class="sw-grp">' . $head . $intro . '<ol class="sw-plist">' . $items . '</ol></section>';
}

/** Step 0, in the room's own words: same five moves as the published plan, with writing here. */
function scout_writing_howto_html( bool $open ): string {
	$flow = array(
		array( 'Pick one prompt', 'Start with Step 1, or anything that sparks you today.' ),
		array( 'Write it here', 'Tap Write. It saves as you type. Voice memos count too.' ),
		array( 'Send it to ' . scout_writing_team(), 'Tap Send when it feels ready. Rough is perfect.' ),
		array( 'We shape it', ucfirst( scout_writing_team() ) . ' turns your answer into a post, page or email and sends it back for your yes.' ),
		array( 'Mark it done', 'Tap Mark done. Your marks are saved to your login, on any device.' ),
	);
	$h = '<details class="sw-howto"' . ( $open ? ' open' : '' ) . '><summary><span class="sw-label">Step 0</span><span class="sw-h2">How to <em>use</em> this</span><span class="sw-chev" aria-hidden="true"></span></summary><ol class="sh-steps sw-flow">';
	foreach ( $flow as $f ) {
		$h .= '<li class="sh-step"><b>' . esc_html( $f[0] ) . '</b><span>' . esc_html( $f[1] ) . '</span></li>';
	}
	$h .= '</ol><div class="sw-week"><h3>A gentle <em>week</em></h3><div class="sw-days">';
	foreach ( scout_writing_copy( 'week' ) as $day ) {
		$h .= '<div class="sw-day"><span class="sw-label">' . esc_html( $day['label'] ) . '</span><p><strong>' . esc_html( $day['title'] ) . '</strong>' . esc_html( $day['text'] ) . '</p></div>';
	}
	$h .= '</div><p class="sw-note">Skip a week and nothing breaks. You can stop any time and pick up right where you left off.</p></div>'
		. '<p class="sw-private">Everything you write here is private. Only you and ' . scout_writing_team() . ' can see it, and nothing goes on the site until you say yes.</p></details>';
	return $h;
}

/** Every step, with its blocks, groups and reference lists. */
function scout_writing_steps_html( array $marks, array $entries ): string {
	$d = scout_writing_data();
	// Prompts by step, then by group, in the plan's order.
	$by = array();
	foreach ( $d['prompts'] as $p ) {
		$by[ (int) $p['step'] ][ $p['group'] ][] = $p;
	}
	// Where the reference lists sit: their own "step", else the step with the matching key.
	$rules_step  = $d['social_rules'] ? scout_writing_ref_step( $d['social_rules'], 'social' ) : 0;
	$guests_step = ! empty( $d['guests']['groups'] ) ? scout_writing_ref_step( $d['guests'], 'radio' ) : 0;
	$inv_step    = 0;
	if ( $d['inventory'] ) {
		$inv_step = isset( $d['inventory_step'] ) ? (int) $d['inventory_step'] : scout_writing_ref_step( array(), 'have' );
		foreach ( $inv_step ? array() : $d['steps'] as $st ) {
			if ( empty( $by[ (int) $st['n'] ] ) ) {
				$inv_step = (int) $st['n']; // The first step with no prompts of its own.
				break;
			}
		}
	}
	$first = $d['steps'] ? (int) $d['steps'][0]['n'] : 0;
	$out   = '';
	foreach ( $d['steps'] as $st ) {
		$n      = (int) $st['n'];
		$count  = isset( $by[ $n ] ) ? array_sum( array_map( 'count', $by[ $n ] ) ) : 0;
		$done   = $count ? count( array_filter( $marks['done'], static fn( $id ) => (int) ( $d['by_id'][ $id ]['step'] ?? 0 ) === $n ) ) : 0;
		$body   = '';
		$placed = array();
		if ( $rules_step === $n ) {
			$body .= '<div class="sw-rules" data-static="1">';
			foreach ( array( 'always' => 'Always', 'never' => 'Never' ) as $k => $label ) {
				$body .= '<div class="sw-rule sw-rule-' . $k . '"><p class="sw-label">' . $label . '</p><ul>';
				foreach ( (array) ( $d['social_rules'][ $k ] ?? array() ) as $line ) {
					$body .= '<li>' . esc_html( (string) $line ) . '</li>';
				}
				$body .= '</ul></div>';
			}
			$body .= '</div>';
		}
		// Blocks: titled, folding sets of groups (the first one starts open).
		$open = true;
		foreach ( $d['blocks'] as $b ) {
			if ( $b['step'] !== $n ) {
				continue;
			}
			$inner  = '';
			$c      = 0;
			$issues = false;
			foreach ( $b['intro'] as $para ) {
				$inner .= '<p class="sw-subintro">' . esc_html( $para ) . '</p>';
			}
			$groups = '';
			foreach ( $b['groups'] as $gt ) {
				$placed[ $gt ] = true;
				$c            += count( $by[ $n ][ $gt ] ?? array() );
				$issues        = $issues || ! empty( $d['groups'][ $n . '|' . $gt ]['issue'] );
				$groups       .= scout_writing_group_html( $n, $gt, $by[ $n ][ $gt ] ?? array(), $marks, $entries, 'h4' );
			}
			$inner .= $issues ? '<div class="sw-issues">' . $groups . '</div>' : $groups;
			$accent = '' !== $b['accent'] && str_ends_with( $b['title'], $b['accent'] ) ? $b['accent'] : '';
			$lead   = trim( substr( $b['title'], 0, strlen( $b['title'] ) - strlen( $accent ) ) );
			$body  .= '<details class="sw-block" id="sw-b-' . esc_attr( $b['id'] ) . '"' . ( $open ? ' open' : '' ) . '><summary>'
				. '<span class="sw-subtxt"><span class="sw-label">' . esc_html( $b['label'] ) . '</span><span class="sw-h3">' . scout_writing_title_html( $lead, $accent ) . '</span></span>'
				. '<span class="sw-bcount">' . (int) $c . ( 1 === $c ? ' prompt' : ' prompts' ) . '</span><span class="sw-chev" aria-hidden="true"></span></summary><div class="sw-bbody">' . $inner . '</div></details>';
			$open   = false;
		}
		foreach ( $by[ $n ] ?? array() as $gt => $ps ) {
			if ( ! isset( $placed[ $gt ] ) ) {
				$body .= scout_writing_group_html( $n, $gt, $ps, $marks, $entries );
			}
		}
		if ( $guests_step === $n ) {
			$body .= '<section class="sw-guests" data-static="1"><h3 class="sw-gh">' . esc_html( (string) ( $d['guests']['title'] ?? '' ) ) . '</h3><p class="sw-gintro">' . esc_html( (string) ( $d['guests']['intro'] ?? '' ) ) . '</p><div class="sw-gcats">';
			foreach ( (array) $d['guests']['groups'] as $g ) {
				$body .= '<div class="sw-gcat"><p class="sw-label">' . esc_html( (string) ( $g['title'] ?? '' ) ) . '</p><ul>';
				foreach ( (array) ( $g['names'] ?? array() ) as $nm ) {
					$body .= '<li>' . esc_html( (string) $nm ) . '</li>';
				}
				$body .= '</ul></div>';
			}
			$body .= '</div></section>';
		}
		if ( $inv_step === $n ) {
			$body .= '<div class="sw-inv" data-static="1">';
			foreach ( $d['inventory'] as $i ) {
				$i     = wp_parse_args( (array) $i, array( 'tier' => '', 'name' => '', 'sub' => '', 'where' => '', 'becomes' => '' ) );
				$body .= '<article class="sw-invc"><p class="sw-label">' . esc_html( $i['tier'] ) . '</p><h4>' . esc_html( $i['name'] ) . '</h4><p class="sw-invsub">' . esc_html( $i['sub'] ) . '</p>'
					. '<p class="sw-invl"><span>Lives in</span>' . esc_html( $i['where'] ) . '</p><p class="sw-invl"><span>Becomes</span>' . esc_html( $i['becomes'] ) . '</p></article>';
			}
			$body .= '</div>';
		}
		if ( $count ) {
			$meta = '<span class="sw-cnt">' . $count . ( 1 === $count ? ' prompt' : ' prompts' ) . '</span><span class="sw-sdone" data-done="' . $n . '">' . ( $done ? $done . ' done' : '' ) . '</span>';
		} else {
			$meta = $inv_step === $n ? '<span class="sw-cnt">' . count( $d['inventory'] ) . ' things to reuse</span>' : '';
		}
		$out .= '<details class="sw-step sw-step-' . esc_attr( $st['key'] ) . ( $count ? '' : ' sw-step-ref' ) . '" id="sw-step-' . $n . '" data-step="' . $n . '"' . ( $first === $n ? ' open' : '' ) . '><summary>'
			. '<span class="sw-snum" aria-hidden="true">' . $n . '</span><span class="sw-stext"><span class="sw-label">Step ' . $n . ( $first === $n ? ' <span class="sw-start">Start here</span>' : '' ) . '</span>'
			. '<span class="sw-h2">' . scout_writing_title_html( (string) $st['lead'], (string) $st['accent'], (string) $st['tail'] ) . '</span>'
			. '<span class="sw-slede">' . esc_html( $st['lede'] ) . '</span><span class="sw-smeta">' . $meta . '</span></span><span class="sw-chev" aria-hidden="true"></span></summary>'
			. '<div class="sw-sbody">' . $body . '</div></details>';
	}
	return $out;
}

/** The panel's buttons and notes, shared by the inline panel and the one-prompt page. */
function scout_writing_panel_html( string $pid, array $payload, bool $form ): string {
	$prompt   = scout_writing_prompt( $pid );
	$can_memo = current_user_can( 'upload_files' );
	$memos    = '';
	foreach ( $payload['memos'] as $m ) {
		$memos .= '<li>' . ( $m['audio'] ? '<audio controls preload="none" src="' . esc_url( $m['url'] ) . '"></audio>' : '' ) . '<a href="' . esc_url( $m['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $m['title'] ) . '</a></li>';
	}
	$draft = '';
	if ( $payload['draft'] ) {
		$draft = 'A draft post was started from this on ' . esc_html( $payload['draft']['when'] ) . '.' . ( $payload['draft']['url'] ? ' <a href="' . esc_url( $payload['draft']['url'] ) . '">Open the draft</a>' : '' );
	}
	$h  = '<div class="sw-panel" data-pid="' . esc_attr( $pid ) . '">';
	$h .= $form ? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( SCOUT_WRITING_NONCE, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="scout_writing"><input type="hidden" name="pid" value="' . esc_attr( $pid ) . '"><input type="hidden" name="back" value="write">' : '';
	$h .= '<label class="sw-sr" for="sw-text-' . esc_attr( $pid ) . '">Your answer</label>';
	$h .= '<textarea class="sw-text" id="sw-text-' . esc_attr( $pid ) . '" name="text" rows="12" placeholder="Start anywhere. Rough is perfect.">' . esc_textarea( $payload['text'] ) . '</textarea>';
	$h .= '<div class="sw-pbar"><span class="sw-saved" aria-live="polite">' . esc_html( $payload['saved'] ) . '</span><span class="sw-count"></span></div>';
	$h .= '<ul class="sw-memos"' . ( $memos ? '' : ' hidden' ) . '>' . $memos . '</ul>';
	$h .= '<div class="sw-pacts">';
	$h .= $form ? '<button type="submit" name="do" value="save" class="sw-btn sw-save">Save</button>' : '';
	$h .= $can_memo ? '<button type="button" class="sw-btn sw-memo" data-js-only hidden>Add a voice memo</button>' : '';
	$h .= '<button type="submit" name="do" value="send" class="sw-btn sw-primary sw-send">Send to ' . esc_html( scout_writing_team() ) . '</button>';
	// Website and newsletter prompts only. The inline template (no prompt yet) carries it hidden; the script shows it.
	if ( ! $prompt || scout_writing_can_draft( $prompt ) ) {
		$h .= '<button type="submit" name="do" value="draft" class="sw-link sw-draft"' . ( $payload['draft'] || ! $prompt ? ' hidden' : '' ) . '>Start a draft from this</button>';
	}
	$h .= $form ? '' : '<button type="button" class="sw-link sw-close">Close</button>';
	$h .= '</div>';
	$h .= '<p class="sw-sent"' . ( $payload['sent'] ? '' : ' hidden' ) . '>' . esc_html( $payload['sent'] ) . '</p>';
	$h .= '<p class="sw-draftnote"' . ( $draft ? '' : ' hidden' ) . '>' . $draft . '</p>';
	$h .= '<p class="sw-msg" role="status"' . ( $payload['note'] ? '' : ' hidden' ) . '>' . esc_html( $payload['note'] ) . '</p>';
	$h .= $form ? '</form>' : '';
	return $h . '</div>';
}

/** Notes after a no-JS action. */
function scout_writing_flash(): string {
	// phpcs:disable WordPress.Security.NonceVerification -- display only.
	$msg = isset( $_GET['sw_msg'] ) ? sanitize_key( wp_unslash( $_GET['sw_msg'] ) ) : '';
	$err = isset( $_GET['sw_err'] ) ? sanitize_key( wp_unslash( $_GET['sw_err'] ) ) : '';
	// phpcs:enable
	$text = array(
		'save'      => 'Saved.',
		'send'      => 'Sent. ' . ucfirst( scout_writing_team() ) . ' has it now.',
		'draft'     => 'A draft post is waiting in Posts for ' . scout_writing_team() . ' to shape.',
		'sw_empty' => 'Write a little (or add a voice memo) first.',
		'sw_cap'   => 'Sign in again.',
	);
	if ( $err ) {
		return '<div class="sw-flash is-err" role="alert">' . esc_html( $text[ $err ] ?? 'That didn\'t work. Try again.' ) . '</div>';
	}
	return $msg && isset( $text[ $msg ] ) ? '<div class="sw-flash" role="status">' . esc_html( $text[ $msg ] ) . '</div>' : '';
}

/**
 * The kit's status line and closed box. On a site mid-update an older kit (1.9.x) may be the one loaded,
 * without status() or fold_open(), so the same markup is printed here instead.
 *
 * @param string $go Optional button HTML, already escaped.
 */
function scout_writing_status_line( string $state, string $headline, string $detail = '', string $go = '' ): void {
	if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
		Scout_Admin_UI::status( $state, $headline, $detail, $go );
		return;
	}
	$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
	echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
	if ( '' !== $detail ) {
		echo ' <span>' . esc_html( $detail ) . '</span>';
	}
	if ( '' !== $go ) {
		echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
	}
	echo '</p></section>';
}

/** One row in My writing (stacked kit row): the prompt with Keep writing beside it, then status, step, group, words and last edit. */
function scout_writing_mine_row( string $pid, array $e ): string {
	$p   = scout_writing_data()['by_id'][ $pid ];
	$mod = strtotime( $e['post']->post_modified_gmt . ' UTC' );
	return '<li class="sh-row sw-mrow" data-pid="' . esc_attr( $pid ) . '"><div><p class="sh-row-name sw-mq">' . esc_html( $p['q'] ) . '</p></div>'
		. '<div class="sh-row-facts"><span class="sw-status sw-s-' . esc_attr( $e['status'] ) . '">' . esc_html( scout_writing_status_label( $e['status'] ) ) . '</span>'
		. '<span class="sh-tag">Step ' . (int) $p['step'] . '</span>' . ( '' !== (string) $p['group'] ? '<span class="sh-tag">' . esc_html( $p['group'] ) . '</span>' : '' )
		. '<span class="sh-tag">' . (int) scout_writing_words( scout_writing_text( $e['post'] ) ) . ' words</span><span>Last edited ' . esc_html( wp_date( 'M j, g:i a', $mod ) ) . '</span></div>'
		. '<div class="sh-row-go"><a class="button" data-open="' . esc_attr( $pid ) . '" href="' . esc_url( scout_writing_url( $pid ) ) . '">Keep writing</a></div></li>';
}

/** The room: Your Writing. */
function scout_writing_render() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$d       = scout_writing_data();
	$user_id = get_current_user_id();
	$marks   = scout_writing_marks( $user_id );
	$posts   = scout_writing_entries( $user_id );
	$entries = array();
	foreach ( $posts as $e ) {
		$pid = (string) get_post_meta( $e->ID, '_sw_prompt_id', true );
		if ( isset( $d['by_id'][ $pid ] ) && ! isset( $entries[ $pid ] ) ) {
			$entries[ $pid ] = array( 'post' => $e, 'status' => scout_writing_status( $e ) );
		}
	}
	$total = count( $d['prompts'] );
	$label = scout_writing_menu_label();
	if ( ! $total ) {
		// Only administrators reach this: writers have no menu until there are prompts.
		Scout_Admin_UI::open( $label, 'The writing room opens for writers once it has a prompts file. Until then, only administrators see this.' );
		scout_writing_status_line( 'off', 'No prompts yet.', 'Writers see nothing until there are prompts.' );
		echo '<section class="sh-box sw"><h2 class="sh-title">Two ways to add prompts</h2><ol class="sw-setup">';
		echo '<li><b>From the theme.</b> Add a <code>writing</code> section to the theme\'s <code>scout-setup.json</code> that names the prompts file, for example <code>{ "writing": { "prompts": "writing-prompts.json" } }</code>. It then updates with every theme release.</li>';
		echo '<li><b>Upload a file.</b> <a href="' . esc_url( admin_url( 'admin.php?page=' . SCOUT_WRITING_SETTINGS_PAGE ) ) . '">Settings</a> takes a prompts file (.json) when the theme has none.</li>';
		echo '</ol></section>';
		Scout_Admin_UI::close();
		return;
	}

	// One prompt on its own page: the no-JS path, and the dashboard's "Write this one".
	$write = isset( $_GET['write'] ) ? scout_writing_clean_id( wp_unslash( $_GET['write'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $write && isset( $d['by_id'][ $write ] ) ) {
		$p    = $d['by_id'][ $write ];
		$pay  = scout_writing_payload( $user_id, $write );
		$star = $pay['star'];
		$done = $pay['done'];
		Scout_Admin_UI::open( $label, 'Write your answer here, in your own words. Send it to ' . scout_writing_team() . ' when it feels ready.' );
		echo '<div class="sw">';
		echo '<p class="sw-back"><a href="' . esc_url( scout_writing_url() . '#sw-p-' . $write ) . '">&larr; All your prompts</a></p>';
		echo scout_writing_flash(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '<section class="sh-box sw-one sw-p" data-pid="' . esc_attr( $write ) . '"><p class="sw-label">Step ' . (int) $p['step'] . ' &middot; ' . esc_html( scout_writing_step_title( (int) $p['step'] ) ) . ' &middot; ' . esc_html( $p['group'] ) . '</p>';
		echo '<h2 class="sw-q">' . esc_html( $p['q'] ) . '</h2><p class="sw-why">' . esc_html( $p['why'] ) . '</p>';
		echo '<div class="sw-row"><div class="sw-meta">';
		foreach ( (array) $p['dest'] as $k ) {
			echo '<span class="sw-chip sw-d-' . esc_attr( $k ) . '">' . esc_html( $d['dest_labels'][ $k ] ?? $k ) . '</span>';
		}
		echo ( isset( $d['time_labels'][ $p['t'] ] ) ? '<span class="sw-time">' . esc_html( $d['time_labels'][ $p['t'] ] ) . '</span>' : '' );
		echo '<span class="sw-status sw-s-' . esc_attr( $pay['status'] ) . '"' . ( $pay['status'] ? '' : ' hidden' ) . '>' . esc_html( $pay['label'] ) . '</span></div><div class="sw-acts">';
		echo '<a class="sw-act sw-star" role="button" data-act="star" aria-pressed="' . ( $star ? 'true' : 'false' ) . '" href="' . esc_url( add_query_arg( 'back', 'write', scout_writing_mark_url( $write, 'star' ) ) ) . '"><span class="sw-ic" aria-hidden="true">' . ( $star ? '&#9733;' : '&#9734;' ) . '</span><span class="sw-lb">' . ( $star ? 'Starred' : 'Star' ) . '</span></a>';
		echo '<a class="sw-act sw-done" role="button" data-act="done" aria-pressed="' . ( $done ? 'true' : 'false' ) . '" href="' . esc_url( add_query_arg( 'back', 'write', scout_writing_mark_url( $write, 'done' ) ) ) . '"><span class="sw-ic" aria-hidden="true">' . ( $done ? '&#10003;' : '&#9675;' ) . '</span><span class="sw-lb">' . ( $done ? 'Done' : 'Mark done' ) . '</span></a>';
		echo '</div></div>';
		echo scout_writing_panel_html( $write, $pay, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '</section></div>';
		Scout_Admin_UI::close();
		return;
	}

	// Older links open "My writing" with ?view=mine. It is now the box at the top, shown in full for that link.
	$view    = isset( $_GET['view'] ) && 'mine' === $_GET['view'] ? 'mine' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
	$written = count( $entries );
	$sent    = count( array_filter( $entries, static fn( $e ) => (bool) get_post_meta( $e['post']->ID, '_sw_sent_at', true ) ) );

	// The title and its small label above (text.kicker in the theme's setup file).
	if ( method_exists( 'Scout_Admin_UI', 'page_head' ) ) {
		Scout_Admin_UI::page_head( 'Your writing room', (string) scout_writing_copy( 'kicker' ) );
	}
	Scout_Admin_UI::open( $label, 'Every prompt from your writing plan. Pick one, write it in your own words, and send it to ' . scout_writing_team() . ' when it\'s ready.' );
	echo '<div class="sw">';
	// Status line, the kit's markup with live numbers the script keeps up to date.
	echo '<section class="sh-box"><p class="sh-status is-' . ( $written ? 'good' : 'off' ) . '"><span class="sh-dot" aria-hidden="true"></span>';
	echo '<strong><span id="sw-done-n">' . count( $marks['done'] ) . '</span> of ' . (int) $total . ' prompts done.</strong> ';
	echo '<span><span id="sw-written-n">' . (int) $written . '</span> written, ' . (int) $sent . ' sent to ' . esc_html( scout_writing_team() ) . '.</span></p></section>';
	echo scout_writing_flash(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.

	// My writing: the newest five, the rest one tap away (open for ?view=mine).
	echo '<section class="sh-box sw-mine" id="sw-mine" aria-labelledby="sw-mine-h"><h2 class="sh-title" id="sw-mine-h">My writing</h2>';
	if ( ! $entries ) {
		echo '<p class="sw-empty-mine">Nothing written yet. Open any prompt below and tap Write. Everything you write shows up here.</p>';
	} else {
		$rows = array();
		foreach ( $entries as $pid => $e ) {
			$rows[] = scout_writing_mine_row( (string) $pid, $e );
		}
		echo '<ul class="sh-rows is-stacked sw-mlist">' . implode( '', array_slice( $rows, 0, 5 ) ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		if ( count( $rows ) > 5 ) {
			echo '<details class="sw-more"' . ( 'mine' === $view ? ' open' : '' ) . '><summary>' . esc_html( sprintf( 'Show all %d pieces', count( $rows ) ) ) . '</summary>';
			echo '<ul class="sh-rows is-stacked sw-mlist">' . implode( '', array_slice( $rows, 5 ) ) . '</ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
	}
	echo '</section>';

	// All prompts.
	echo '<section class="sw-all" aria-labelledby="sw-all-h"><h2 class="sw-all-h" id="sw-all-h">All prompts</h2>';
	echo scout_writing_howto_html( ! $entries ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.

	$dest_counts = array();
	$time_counts = array();
	$step_has    = array();
	foreach ( $d['prompts'] as $p ) {
		foreach ( (array) $p['dest'] as $k ) {
			$dest_counts[ $k ] = ( $dest_counts[ $k ] ?? 0 ) + 1;
		}
		$time_counts[ $p['t'] ] = ( $time_counts[ $p['t'] ] ?? 0 ) + 1;
		$step_has[ (int) $p['step'] ] = true;
	}
	echo '<section class="sw-tools" data-js-only hidden aria-labelledby="sw-tools-h"><h3 class="sw-h2" id="sw-tools-h">Find a prompt</h3><div class="sw-trow">';
	echo '<label class="sw-search"><span class="sw-sr">Search prompts</span><input type="search" id="sw-q" placeholder="' . esc_attr( scout_writing_copy( 'search_hint' ) ) . '" autocomplete="off"></label>';
	echo '<label class="sw-sr" for="sw-stepsel">Show one step</label><select id="sw-stepsel"><option value="">All steps</option>';
	foreach ( $d['steps'] as $st ) {
		if ( $step_has[ (int) $st['n'] ] ?? false ) {
			echo '<option value="' . (int) $st['n'] . '">Step ' . (int) $st['n'] . ': ' . esc_html( $st['title'] ) . '</option>';
		}
	}
	echo '</select></div>';
	// Filter rows only for the lists the prompts file has.
	foreach ( array( 'dest' => 'Where it goes', 'time' => 'How long it takes' ) as $list => $heading ) {
		$counts = 'dest' === $list ? $dest_counts : $time_counts;
		$items  = array_filter( $d[ $list ], static fn( $x ) => is_array( $x ) && isset( $x['key'], $x['label'] ) );
		if ( ! $items ) {
			continue;
		}
		echo '<div class="sw-fgroup"><span class="sw-label" id="sw-' . $list . '-l">' . esc_html( $heading ) . '</span><div class="sw-fbtns" role="group" aria-labelledby="sw-' . $list . '-l">';
		foreach ( $items as $x ) {
			echo '<button type="button" class="sw-fbtn" data-' . $list . '="' . esc_attr( $x['key'] ) . '" aria-pressed="false">' . esc_html( $x['label'] ) . ' <span class="sw-fc">' . (int) ( $counts[ $x['key'] ] ?? 0 ) . '</span></button>';
		}
		echo '</div></div>';
	}
	echo '<div class="sw-fgroup"><span class="sw-label" id="sw-mine-l">Your marks</span><div class="sw-fbtns" role="group" aria-labelledby="sw-mine-l">';
	echo '<button type="button" class="sw-fbtn" id="sw-only-star" aria-pressed="false">&#9734; Starred only</button><button type="button" class="sw-fbtn" id="sw-hide-done" aria-pressed="false">Hide done</button></div></div>';
	echo '<div class="sw-tfoot"><p class="sw-showing" id="sw-showing" aria-live="polite">Showing <b>' . (int) $total . '</b> prompts</p><div class="sw-tacts">';
	echo '<button type="button" class="sw-link" id="sw-clear">Clear filters</button><button type="button" class="sw-link" id="sw-open-all">Open all</button><button type="button" class="sw-link" id="sw-close-all">Close all</button></div></div></section>';
	echo '<p class="sw-empty" id="sw-empty" hidden>No prompts match. Try fewer filters, or <button type="button" class="sw-link" id="sw-clear2">clear them all</button>.</p>';

	echo '<div class="sw-steps" id="sw-steps">' . scout_writing_steps_html( $marks, $entries ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	echo '</section>';

	// The inline panel, cloned by the script under any prompt.
	$blank = array( 'text' => '', 'saved' => '', 'memos' => array(), 'draft' => null, 'sent' => '', 'note' => '' );
	echo '<template id="sw-panel-tpl">' . scout_writing_panel_html( '__PID__', $blank, false ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	echo '<p class="sw-foot">Your stars, done marks and writing are saved to your login, so they follow you to any device. Only you and ' . esc_html( scout_writing_team() ) . ' can see what you write.</p>';
	echo '</div>';
	Scout_Admin_UI::close();
}

/* ------------------------------------------------------------------ dashboard welcome */

/** The prompt to suggest next: a starred one not done, else the first prompt of the first step not done, else any. */
function scout_writing_next( int $user_id ): ?array {
	$d     = scout_writing_data();
	$marks = scout_writing_marks( $user_id );
	foreach ( array_reverse( $marks['star'] ) as $id ) {
		if ( ! in_array( $id, $marks['done'], true ) ) {
			return $d['by_id'][ $id ];
		}
	}
	$first = $d['steps'] ? (int) $d['steps'][0]['n'] : 0;
	foreach ( array( true, false ) as $first_only ) {
		foreach ( $d['prompts'] as $p ) {
			if ( ( ! $first_only || $first === (int) $p['step'] ) && ! in_array( $p['id'], $marks['done'], true ) ) {
				return $p;
			}
		}
	}
	return null;
}

/** The welcome's styles on the dashboard: the Scout admin kit for its tokens, then writing-room.css. */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'index.php' !== $hook || ! current_user_can( 'edit_posts' ) || ! scout_writing_has_prompts() ) {
		return;
	}
	$base = plugins_url( 'assets/', SCOUT_WRITING_FILE );
	Scout_Admin_UI::enqueue( $base . 'scout-admin.css' );
	wp_enqueue_style( 'scout-writing', $base . 'writing-room.css', array( 'scout-admin' ), SCOUT_WRITING_VERSION );
	$brand = scout_writing_brand()['font_faces'] . scout_writing_brand_css( '.sw-welcome' );
	if ( '' !== $brand ) {
		wp_add_inline_style( 'scout-writing', $brand );
	}
} );

/**
 * The writing welcome on the dashboard: a full-width panel at the very top, above every box
 * (Joy, 2026-11-03: "this should be better and bigger... she can't use or won't if it's too small").
 */
add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( 'edit_posts' ) || ! scout_writing_has_prompts() ) {
		return;
	}
	$user    = wp_get_current_user();
	$first   = trim( (string) $user->first_name ) ?: trim( (string) strtok( (string) $user->display_name, ' ' ) );
	$user_id = (int) $user->ID;
	$total   = count( scout_writing_data()['prompts'] );
	$done    = count( scout_writing_marks( $user_id )['done'] );
	$entries = scout_writing_entries( $user_id );
	$sent    = 0;
	foreach ( $entries as $e ) {
		if ( 'ready' === scout_writing_status( $e ) ) {
			$sent++;
		}
	}
	$next = scout_writing_next( $user_id );
	$pct  = $total ? max( 2, round( 100 * $done / $total ) ) : 0;
	?>
	<section class="sh sw-welcome" aria-labelledby="sw-welcome-h"><div class="sw-wgrid">
		<div>
			<p class="k">✦ <?php echo esc_html( scout_writing_copy( 'dashboard_kicker' ) ); ?></p>
			<h2 id="sw-welcome-h"><?php echo esc_html( $first ? 'Welcome back, ' . $first . '.' : 'Welcome back.' ); ?> Here's your <em>next</em> prompt.</h2>
			<p class="lead">One prompt a week is plenty. Write a little or a lot, or talk it into a voice memo. It saves as you go, and we shape it with you.</p>
			<?php if ( $next ) : ?>
				<p class="prompt"><?php echo esc_html( $next['q'] ); ?></p>
				<?php if ( '' !== $next['why'] ) : ?>
					<p class="why"><?php echo esc_html( $next['why'] ); ?></p>
				<?php endif; ?>
				<div class="acts">
					<a class="go" href="<?php echo esc_url( scout_writing_url( $next['id'] ) ); ?>">Write this one</a>
					<a class="alt" href="<?php echo esc_url( scout_writing_url() ); ?>">Pick a different prompt</a>
					<?php if ( $entries ) : ?>
						<a class="alt" href="<?php echo esc_url( add_query_arg( 'view', 'mine', scout_writing_url() ) ); ?>">Keep writing</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="prompt">Every prompt is marked done. That is the whole plan. Beautiful work.</p>
				<div class="acts"><a class="go" href="<?php echo esc_url( add_query_arg( 'view', 'mine', scout_writing_url() ) ); ?>">See everything you wrote</a></div>
			<?php endif; ?>
		</div>
		<aside aria-label="Your progress">
			<p class="num"><?php echo (int) $done; ?> <small>of <?php echo (int) $total; ?> prompts done</small></p>
			<div class="bar" aria-hidden="true"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
			<ul class="facts">
				<li><?php echo (int) count( $entries ); ?> written</li>
				<li><?php echo (int) $sent; ?> sent to Scout</li>
			</ul>
			<p class="tip">Prefer talking? Open any prompt and tap <b>Add a voice memo</b>. When a piece feels ready, tap <b>Send to Scout</b>.</p>
		</aside>
	</div></section>
	<?php
} );

/* ------------------------------------------------------------------ Scout's view: All writing */

add_filter( 'manage_' . SCOUT_WRITING_CPT . '_posts_columns', function () {
	return array(
		'cb'           => '<input type="checkbox" />',
		'title'        => 'Prompt',
		'sw_step'     => 'Step',
		'sw_status'   => 'Status',
		'author'       => 'Writer',
		'sw_modified' => 'Last edited',
	);
} );

add_action( 'manage_' . SCOUT_WRITING_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	$post = get_post( $post_id );
	if ( 'sw_step' === $col ) {
		$step = (int) get_post_meta( $post_id, '_sw_step', true );
		$p    = scout_writing_prompt( (string) get_post_meta( $post_id, '_sw_prompt_id', true ) );
		echo esc_html( 'Step ' . $step . ( $p ? ', ' . $p['group'] : '' ) );
	} elseif ( 'sw_status' === $col ) {
		echo esc_html( scout_writing_status_label( scout_writing_status( $post ) ) );
		$sent = (int) get_post_meta( $post_id, '_sw_sent_at', true );
		if ( $sent ) {
			echo '<br><small>' . esc_html( 'Sent ' . wp_date( 'M j, g:i a', $sent ) ) . '</small>';
		}
		if ( get_post_meta( $post_id, '_sw_draft_post', true ) ) {
			echo '<br><small>Draft post started</small>';
		}
	} elseif ( 'sw_modified' === $col ) {
		echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $post->post_modified_gmt . ' UTC' ) ) );
	}
}, 10, 2 );

add_filter( 'manage_edit-' . SCOUT_WRITING_CPT . '_sortable_columns', function ( $cols ) {
	$cols['sw_modified'] = 'modified';
	return $cols;
} );

add_filter( 'views_edit-' . SCOUT_WRITING_CPT, function ( $views ) {
	$sent = new WP_Query(
		array(
			'post_type'      => SCOUT_WRITING_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_sw_sent_at', // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$on = isset( $_GET['sw_sent'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$views['sw_sent'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . SCOUT_WRITING_CPT . '&sw_sent=1' ) ) . '"' . ( $on ? ' class="current" aria-current="page"' : '' ) . '>Sent to Scout <span class="count">(' . (int) $sent->found_posts . ')</span></a>';
	return $views;
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || SCOUT_WRITING_CPT !== $q->get( 'post_type' ) ) {
		return;
	}
	if ( isset( $_GET['sw_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$q->set( 'meta_key', '_sw_sent_at' );
	}
	if ( ! $q->get( 'orderby' ) ) {
		$q->set( 'orderby', 'modified' );
		$q->set( 'order', 'DESC' );
	}
} );

/** On an entry's screen: what it answers, its memos and its draft. */
add_action( 'add_meta_boxes_' . SCOUT_WRITING_CPT, function ( $post ) {
	add_meta_box( 'sw-about', 'From the writing room', function ( $post ) {
		$p = scout_writing_prompt( (string) get_post_meta( $post->ID, '_sw_prompt_id', true ) );
		if ( $p ) {
			echo '<p><b>Step ' . (int) $p['step'] . ', ' . esc_html( $p['group'] ) . '</b><br>' . esc_html( $p['why'] ) . '</p>';
			echo '<p>Where it could go: ' . esc_html( implode( ', ', scout_writing_dest_labels( $p ) ) ) . '</p>';
		}
		echo '<p>Status: ' . esc_html( scout_writing_status_label( scout_writing_status( $post ) ) ) . '</p>';
		foreach ( scout_writing_memos( $post ) as $att ) {
			$url = wp_get_attachment_url( $att );
			echo '<p>' . ( 0 === strpos( (string) get_post_mime_type( $att ), 'audio/' ) ? '<audio controls preload="none" style="width:100%" src="' . esc_url( $url ) . '"></audio><br>' : '' ) . '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $att ) ) . '</a></p>';
		}
		$draft = (int) get_post_meta( $post->ID, '_sw_draft_post', true );
		if ( $draft && get_post( $draft ) ) {
			echo '<p><a href="' . esc_url( (string) get_edit_post_link( $draft ) ) . '">Open the draft post</a> (' . esc_html( get_post_status( $draft ) ) . ')</p>';
		}
		echo '<p class="description">Private. Never shown on the site.</p>';
	}, null, 'side', 'high' );
} );
