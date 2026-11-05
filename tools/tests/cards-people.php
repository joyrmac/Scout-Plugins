<?php
// Scout Cards reads from Scout People: the one-time move matches each card person to
// a profile (login, then email, then exact name), never overwrites a People value,
// makes card only profiles for the rest, and every card, share page, contact file and
// the links page render the same before and after. The second move copies each card's
// intro, "why talk to them" and tags into the profile where it has none, and the card
// reads them from People from then on. With Scout People off, or a profile gone, cards
// use their own saved details; Card off in People means "not found". The main card
// takes its name, email, logo and intro from Business info unless one is typed, and
// the Cards screen keeps only switches per person while People is on.
// Runs without WordPress:
//   php tools/tests/cards-people.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'SCOUT_CARDS_URL', 'https://site.test/wp-content/plugins/scout-cards/' );
define( 'SCOUT_CARDS_VERSION', '1.0.27' );
define( 'SCOUT_CARDS_FILE', __FILE__ );
define( 'MINUTE_IN_SECONDS', 60 );

// Child mode: render with Scout People switched off (its class never loaded).
$without_people = in_array( '--without-people', $argv, true );

// ---------- the few WordPress functions the code reaches ----------
$GLOBALS['opts']  = array();
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['terms'] = array();
$GLOBALS['users'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['saw']   = array();
$GLOBALS['transients'] = array();
function add_action( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $prio ); return true; }
function add_filter( $h, $cb = null, $prio = 10, $args = 1 ) { return true; }
function has_action( $h, $cb = false ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $x ) { if ( $x[0] === $cb ) { return $x[1]; } } return false; }
function remove_action( $h, $cb, $prio = 10 ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $i => $x ) { if ( $x[0] === $cb ) { unset( $GLOBALS['hooks'][ $h ][ $i ] ); } } return true; }
function apply_filters( $t, $v ) { return $v; }
function __( $s ) { return $s; }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url( $s, $p = null ) { return (string) $s; }
function esc_url_raw( $s, $p = null ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $s ) : ''; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $c ) ? $c : ''; }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function wp_doing_ajax() { return false; }
function get_bloginfo( $k = '' ) { return 'description' === $k ? 'Websites for firms that want results.' : 'Scout Media'; }
function bloginfo( $k ) { echo 'charset' === $k ? 'UTF-8' : esc_html( get_bloginfo( $k ) ); }
function language_attributes() { echo 'lang="en-US"'; }
function body_class( $c = '' ) { echo 'class="' . esc_attr( $c ) . '"'; }
function wp_head() {}
function wp_footer() {}
function wp_enqueue_style() {}
function home_url( $p = '' ) { return 'https://site.test' . ( '' === $p ? '' : $p ); }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function add_query_arg( $k, $v = null, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function get_site_icon_url( $size = 512 ) { return ''; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return in_array( (int) $id, array( 11, 12, 13 ), true ) ? 'https://site.test/img/' . $id . '-' . $size . '.jpg' : false; }
function wp_get_attachment_image_src( $id, $size = 'full' ) { return 21 === (int) $id ? array( 'https://site.test/img/share-21.jpg', 1200, 630 ) : false; }
function get_attached_file( $id ) { return ''; }
function get_posts( $a ) {
	$out    = array();
	$status = (array) ( $a['post_status'] ?? 'publish' );
	foreach ( $GLOBALS['posts'] as $id => $p ) {
		if ( ( $a['post_type'] ?? '' ) === $p->post_type && in_array( $p->post_status, $status, true ) ) {
			$out[] = $id;
		}
	}
	return $out;
}
function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_post_thumbnail_id( $id ) { return (int) ( $GLOBALS['meta'][ $id ]['_thumbnail_id'] ?? 0 ); }
function set_post_thumbnail( $id, $att ) { $GLOBALS['meta'][ $id ]['_thumbnail_id'] = (int) $att; return true; }
function get_term_by( $f, $v, $tax ) { return 'team' === $v && ! empty( $GLOBALS['team_term'] ) ? (object) array( 'term_id' => 5 ) : false; }
function has_term( $term, $tax, $id ) { return in_array( (int) $term, $GLOBALS['terms'][ $id ] ?? array(), true ); }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) { $GLOBALS['terms'][ $id ] = array_map( 'intval', (array) $terms ); return $terms; }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['users'] as $id => $email ) { if ( strtolower( $email ) === strtolower( $v ) ) { return (object) array( 'ID' => $id ); } } return false; }
function is_wp_error( $x ) { return false; }
function wp_insert_post( $a, $err = false ) {
	$id = 200 + count( $GLOBALS['posts'] );
	$GLOBALS['saw'][] = array(
		'group' => has_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ) ),
		'edit'  => has_action( 'save_post_scout_team', array( 'Scout_Team_Editor', 'save' ) ),
	);
	$GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $a['post_type'], 'post_status' => $a['post_status'], 'post_title' => $a['post_title'], 'post_name' => 'publish' === $a['post_status'] ? sanitize_title( $a['post_title'] ) : '' );
	foreach ( (array) ( $a['meta_input'] ?? array() ) as $k => $v ) {
		$GLOBALS['meta'][ $id ][ $k ] = $v;
	}
	return $id;
}
function get_edit_post_link( $id ) { return 'https://site.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
// Business info (Scout Core), when a test sets $GLOBALS['core_business'].
class Scout_Core_Business { public static function get() { return array_merge( array( 'name' => '', 'phone' => '', 'email' => '', 'city' => '', 'region' => '', 'postal' => '', 'country' => '', 'same_as' => '' ), $GLOBALS['core_business'] ?? array() ); } }
function scout_core_business() { return Scout_Core_Business::get(); }
// For drawing the Cards screen.
class Scout_Admin_UI {
	public static function open( $t, $l = '' ) { echo '<div class="sh"><h1>' . esc_html( $t ) . '</h1>'; }
	public static function close() { echo '</div>'; }
	public static function status( $st, $h, $d = '', $go = '' ) { echo '<p class="sh-status">' . esc_html( $h ) . '</p>'; }
	public static function fold_open( $t ) { echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $t ) . '</summary>'; }
	public static function fold_close() { echo '</details>'; }
}
function current_user_can( $c ) { return true; }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function wp_nonce_field( $a = '' ) { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t = '' ) { echo '<p class="submit"><button type="submit">' . esc_html( $t ) . '</button></p>'; }
function get_page_by_path( $p ) { return null; }
function get_query_var( $k ) { return $GLOBALS['qv'][ $k ] ?? ''; }
function status_header( $c ) { $GLOBALS['status'] = $c; }
class Fake_Query { public $is404 = false; public function set_404() { $this->is404 = true; } }
class Scout_Team_Editor { public static function save() {} }

if ( ! $without_people ) {
	require __DIR__ . '/../../scout-team/includes/class-scout-team.php';
}
require __DIR__ . '/../../scout-cards/includes/settings.php';
require __DIR__ . '/../../scout-cards/includes/people.php';
require __DIR__ . '/../../scout-cards/includes/routes.php';
require __DIR__ . '/../../scout-cards/includes/vcard.php';
require __DIR__ . '/../../scout-cards/includes/render.php';
$C = 'Scout_Cards_People';
$S = 'Scout_Cards_Settings';

/** Every page a card has, keyed by address, plus the links page. */
function render_all() {
	Scout_Cards_Settings::flush();
	$people = Scout_Cards_Settings::people();
	$out    = array();
	foreach ( $people as $key => $p ) {
		ob_start();
		Scout_Cards_Render::card_page( $p, $people );
		$out[ $p['url'] ] = ob_get_clean();
		ob_start();
		Scout_Cards_Render::share_page( $p );
		$out[ $p['share'] ] = ob_get_clean();
		$out[ $p['save'] ] = Scout_Cards_VCard::build( $p );
	}
	ob_start();
	Scout_Cards_Render::links_page();
	$out['links'] = ob_get_clean();
	return $out;
}

/** A Cards settings option the way the screen saves it. */
function cards_option( array $people, $live = 1 ) {
	return array(
		'enable_cards' => $live,
		'enable_links' => 1,
		'card_base'    => 'card',
		'links_base'   => 'links',
		'mode'         => 'dark',
		'accent'       => '#0E8FE6',
		'name'         => 'Scout Media',
		'phone'        => '(984) 325-9661',
		'email'        => 'team@scoutraleigh.com',
		'city'         => 'Fuquay-Varina',
		'region'       => 'NC',
		'postal'       => '27526',
		'socials'      => "https://www.instagram.com/scoutraleigh\nhttps://www.linkedin.com/company/scout",
		'footer'       => 'Fuquay-Varina, NC',
		'photo_id'     => 13,
		'share_card_id' => 21,
		'people'       => $people,
		'links'        => array( array( 'label' => 'Contact us', 'sub' => '', 'url' => '/contact/', 'external' => 0 ) ),
	);
}

$sean = array( 'name' => 'Sean McNamara', 'slug' => 'sean', 'title' => 'Co-Founder, Strategy & Technology', 'email' => 'sean@scoutraleigh.com', 'intro' => 'I run the strategy side.', 'pick' => 'Search, ads and tracking.', 'tags' => 'SEO, Ads, Recon', 'photo_id' => 11, 'share_id' => 21, 'spot_heading' => 'Book a call', 'spot_text' => 'Thirty minutes.', 'spot_label' => 'Book', 'spot_sub' => '', 'spot_url' => '/contact/' );
$joy  = array( 'name' => 'Joy McNamara', 'slug' => 'joy', 'title' => 'Co-Founder, Design', 'email' => '', 'intro' => 'I design and build every site.', 'pick' => '', 'tags' => '', 'photo_id' => 0, 'share_id' => 0 );

if ( $without_people ) {
	// Child: the option after the move, Scout People off. Print every page.
	$GLOBALS['opts'] = json_decode( (string) file_get_contents( $argv[2] ), true );
	echo json_encode( render_all() );
	exit( 0 );
}

$T = 'Scout_Team';

// ---------- match order ----------
$cands = array(
	array( 'id' => 1, 'name' => 'Dana Lee', 'email' => 'other@firm.test', 'card_email' => '', 'user' => 0, 'status' => 'publish' ),
	array( 'id' => 2, 'name' => 'Someone Else', 'email' => 'dana@firm.test', 'card_email' => '', 'user' => 0, 'status' => 'publish' ),
	array( 'id' => 3, 'name' => 'Third', 'email' => 'x@firm.test', 'card_email' => '', 'user' => 7, 'status' => 'publish' ),
);
$card = array( 'name' => 'Dana Lee', 'email' => 'Dana@Firm.test', 'user' => 7 );
ok( 3 === $C::match( $card, $cands, true ), 'match: the linked login comes first' );
ok( 2 === $C::match( array( 'user' => 0 ) + $card, $cands, true ), 'match: then the email, in any letter case' );
ok( 1 === $C::match( array( 'user' => 0, 'email' => '' ) + $card, $cands, true ), 'match: then the exact name' );
ok( 0 === $C::match( array( 'name' => 'dana lee', 'email' => '', 'user' => 0 ), $cands, true ), 'match: a name that is not exact does not match' );
$cands[] = array( 'id' => 4, 'name' => 'Card Only', 'email' => '', 'card_email' => 'card@firm.test', 'user' => 0, 'status' => 'publish' );
ok( 4 === $C::match( array( 'name' => 'Nobody', 'email' => 'card@firm.test', 'user' => 0 ), $cands, true ), 'match: the card email counts as an email' );
ok( 2 === $C::match( array( 'user' => 0 ) + $card, $cands, true, array( 3 ) ) && 1 === $C::match( array( 'user' => 0 ) + $card, $cands, true, array( 2 ) ), 'match: a profile already linked to a card is skipped' );
$cands[1]['status'] = 'draft';
ok( 1 === $C::match( array( 'user' => 0 ) + $card, $cands, true ), 'match: a live card skips a profile that is not live' );
ok( 2 === $C::match( array( 'user' => 0 ) + $card, $cands, false ), 'match: a card that is not live can match a draft' );
$cands[1]['status'] = 'trash';
ok( 1 === $C::match( array( 'user' => 0 ) + $card, $cands, false ), 'match: a trashed profile never matches' );

// ---------- never overwrite a People value ----------
$have = $T::shape( array( 'role' => 'Senior Partner', 'card_email' => 'dana.card@firm.test', 'email' => 'dana@firm.test' ) );
$m    = $C::merge( $have, array( 'name' => 'Dana Lee', 'title' => 'Partner', 'email' => 'dana@firm.test', 'photo_id' => 12 ), 'Dana Lee', 11 );
ok( 'Senior Partner' === $m['profile']['role'] && 'dana.card@firm.test' === $m['profile']['card_email'], 'merge: the People title and card email stay' );
ok( 0 === $m['photo'], 'merge: the People photo stays' );
ok( true === $m['profile']['shows']['card'] && true === $m['profile']['shows']['bio'], 'merge: Card is switched on, the other switches stay' );
ok( array( 'title', 'email', 'photo' ) === $m['changed'], 'merge: says which fields the card now shows from People' );
$empty = $T::shape( array() );
$m     = $C::merge( $empty, array( 'name' => 'Dana Lee', 'title' => 'Partner', 'email' => 'dana@firm.test', 'photo_id' => 12 ), 'Dana Lee', 0 );
ok( 'Partner' === $m['profile']['role'] && 'dana@firm.test' === $m['profile']['card_email'] && 12 === $m['photo'], 'merge: missing title, card email and photo are filled in' );
ok( array() === $m['changed'], 'merge: nothing the card shows changes when People was empty' );
ok( '' === $m['profile']['email'] && '' === $m['profile']['bio'], 'merge: the public email and bio are never filled from a card' );

// ---------- shows for new profiles ----------
$new = $C::new_profile( array( 'name' => 'Joy McNamara', 'title' => 'Co-Founder, Design', 'email' => '' ), $T::empty_profile() );
ok( array( 'bio' => false, 'team' => false, 'card' => true, 'byline' => false, 'speaker' => false, 'chat' => false ) === $new['shows'], 'new profile: card on, profile page, team page and the rest off' );
ok( 'Co-Founder, Design' === $new['role'] && '' === $new['card_email'] && '' === $new['bio'], 'new profile: the title moves, nothing else is made up' );
ok( $C::list_is_team( array(), array() ), 'team group: no one in Team yet, so the card people are the team' );
ok( ! $C::list_is_team( array( array( 'id' => 1, 'team' => true, 'status' => 'publish' ) ), array() ), 'team group: People has a team and no card person is in it, so no group' );
ok( $C::list_is_team( array( array( 'id' => 1, 'team' => true, 'status' => 'publish' ) ), array( 1 ) ), 'team group: a card person already in Team means the list is the team' );

// ---------- site 1: cards and no People profiles (like scoutraleigh.com) ----------
$GLOBALS['opts']['scout_team_db'] = 1; // Scout People has made its groups.
$GLOBALS['team_term']             = 1;
$GLOBALS['opts'][ $S::OPTION ]    = cards_option( array( $sean, $joy ) );
add_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ), 10, 3 );
add_action( 'save_post_scout_team', array( 'Scout_Team_Editor', 'save' ), 10, 2 );
$before = render_all();
ok( isset( $before['https://site.test/card/sean/'], $before['https://site.test/card/joy/'] ) && false !== strpos( $before['https://site.test/card/sean/'], 'Sean McNamara' ), 'site 1 before: both cards render' );
$C::maybe_upgrade();
ok( $C::upgraded(), 'site 1: the move ran and recorded its version' );
$made = array_keys( $GLOBALS['posts'] );
ok( 2 === count( $made ), 'site 1: one People profile made for each card person' );
$p1 = $GLOBALS['posts'][ $made[0] ];
ok( 'Sean McNamara' === $p1->post_title && 'publish' === $p1->post_status, 'site 1: the live card made a live profile' );
ok( array( 'bio' => false, 'team' => false, 'card' => true, 'byline' => false, 'speaker' => false, 'chat' => false ) === $GLOBALS['meta'][ $made[0] ]['_scout_team']['shows'], 'site 1: the new profile shows on the card only' );
ok( 11 === get_post_thumbnail_id( $made[0] ) && 'sean@scoutraleigh.com' === $GLOBALS['meta'][ $made[0] ]['_scout_team']['card_email'], 'site 1: the photo and card email moved' );
ok( array( 5 ) === ( $GLOBALS['terms'][ $made[0] ] ?? null ), 'site 1: People had no team yet, so the card people went into Team' );
ok( 2 === count( $GLOBALS['saw'] ) && ! in_array( 10, array_column( $GLOBALS['saw'], 'group' ), true ) && ! in_array( 10, array_column( $GLOBALS['saw'], 'edit' ), true ), 'site 1: People\'s "new person goes in Team" and edit screen saves stayed out of the move' );
ok( 10 === has_action( 'wp_after_insert_post', array( 'Scout_Team', 'new_person_group' ) ) && 10 === has_action( 'save_post_scout_team', array( 'Scout_Team_Editor', 'save' ) ), 'site 1: both People hooks are back afterwards' );
$recs = $GLOBALS['opts'][ $S::OPTION ]['people'];
ok( $made[0] === $recs[0]['profile_id'] && $made[1] === $recs[1]['profile_id'], 'site 1: each card record now points at its profile' );
ok( 'Sean McNamara' === $recs[0]['name'] && 11 === $recs[0]['photo_id'] && 'sean@scoutraleigh.com' === $recs[0]['email'], 'site 1: the card keeps its old details for the fallback' );
$note = $C::take_note();
ok( 0 === strpos( $note, 'Moved 2 card people into People.' ), 'site 1: the note says "Moved 2 card people into People."' );
ok( false !== strpos( $note, 'tags for 2 people now live on their People profiles, under Business card.' ), 'site 1: the note says the card words for 2 people moved to People' );
ok( '' === $C::take_note(), 'site 1: the note shows once' );
$after = render_all();
ok( array_keys( $before ) === array_keys( $after ), 'site 1: the same addresses before and after' );
foreach ( $before as $url => $html ) {
	ok( $html === $after[ $url ], 'site 1 parity: ' . $url . ' is the same before and after' );
}
$people = $S::people();
ok( $made[0] === $people['sean']['profile'], 'site 1: after the move the card reads from People' );
$count = count( $GLOBALS['posts'] );
$C::maybe_upgrade();
ok( count( $GLOBALS['posts'] ) === $count, 'site 1: the move never runs twice' );

// Move 2: the card's words now live on the profile.
ok( 2 === $GLOBALS['opts']['scout_cards_people_db'] && $C::words_copied(), 'site 1: both moves ran and the gate reads 2' );
$sp = $GLOBALS['meta'][ $made[0] ]['_scout_team'];
ok( 'I run the strategy side.' === $sp['card_intro'] && 'Search, ads and tracking.' === $sp['card_why'] && 'SEO, Ads, Recon' === $sp['card_tags'], 'site 1: Sean\'s intro, why talk to him and tags were copied into his profile' );
$jp = $GLOBALS['meta'][ $made[1] ]['_scout_team'];
ok( 'I design and build every site.' === $jp['card_intro'] && '' === $jp['card_why'] && '' === $jp['card_tags'], 'site 1: Joy\'s intro was copied, and her empty why and tags stay empty' );
ok( 'I run the strategy side.' === $recs[0]['intro'] && 'Search, ads and tracking.' === $GLOBALS['opts'][ $S::OPTION ]['people'][0]['pick'], 'site 1: the card keeps its own words for the fallback' );

// The card reads its words from People now, and falls back to its own when People has none.
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_intro'] = 'Strategy, from People.';
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_why']   = 'Ask me about search.';
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_tags']  = 'SEO, Recon';
$S::flush();
$sean_now = $S::people()['sean'];
ok( 'Strategy, from People.' === $sean_now['intro'] && 'Ask me about search.' === $sean_now['pick'] && array( 'SEO', 'Recon' ) === $sean_now['tags'], 'an intro, why and tags changed in People show on the card' );
ok( false !== strpos( render_all()['https://site.test/card/'], 'Ask me about search.' ), 'the main card lists the why from People' );
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_intro'] = '';
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_why']   = '';
$GLOBALS['meta'][ $made[0] ]['_scout_team']['card_tags']  = '';
$S::flush();
$sean_now = $S::people()['sean'];
ok( 'I run the strategy side.' === $sean_now['intro'] && 'Search, ads and tracking.' === $sean_now['pick'] && array( 'SEO', 'Ads', 'Recon' ) === $sean_now['tags'], 'empty in People: the card uses its own saved words, as before' );
$GLOBALS['meta'][ $made[0] ]['_scout_team'] = array_merge( $GLOBALS['meta'][ $made[0] ]['_scout_team'], array( 'card_intro' => 'I run the strategy side.', 'card_why' => 'Search, ads and tracking.', 'card_tags' => 'SEO, Ads, Recon' ) );
$S::flush();
ok( render_all() === $after, 'site 1: with the copied words back, every page is the same as before the moves' );

// The Intro and Tags switches.
$GLOBALS['opts'][ $S::OPTION ]['people'][0]['show_intro'] = 0;
$GLOBALS['opts'][ $S::OPTION ]['people'][0]['show_tags']  = 0;
$S::flush();
$html = render_all()['https://site.test/card/sean/'];
ok( false === strpos( $html, 'I run the strategy side.' ) && false === strpos( $html, 'scc-hero-tags' ), 'switches: Intro and Tags off hide them on the card' );
ok( array() === $S::people()['sean']['tags'] && false === strpos( render_all()['https://site.test/card/'], '<span>Recon</span>' ), 'switches: Tags off hides them on the main card too' );
unset( $GLOBALS['opts'][ $S::OPTION ]['people'][0]['show_intro'], $GLOBALS['opts'][ $S::OPTION ]['people'][0]['show_tags'] );
$S::flush();

// People edits reach the card.
$GLOBALS['meta'][ $made[0] ]['_scout_team']['role'] = 'Co-Founder';
$S::flush();
ok( 'Co-Founder' === $S::people()['sean']['title'], 'a title changed in People shows on the card' );
$GLOBALS['meta'][ $made[0] ]['_scout_team']['role'] = 'Co-Founder, Strategy & Technology';

// Photo alt text from People.
$GLOBALS['meta'][11]['_wp_attachment_image_alt'] = 'Sean at his desk';
$S::flush();
$html = render_all()['https://site.test/card/sean/'];
ok( false !== strpos( $html, 'alt="Sean at his desk"' ), 'the photo\'s alt text from the media library is used on the card' );
unset( $GLOBALS['meta'][11]['_wp_attachment_image_alt'] );

// Scout People off: the card uses its own saved details, unchanged.
$tmp = tempnam( sys_get_temp_dir(), 'scc' );
file_put_contents( $tmp, json_encode( $GLOBALS['opts'] ) );
$child = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --without-people ' . escapeshellarg( $tmp ) ), true );
unlink( $tmp );
ok( is_array( $child ) && array_keys( $child ) === array_keys( $before ), 'People off: the same addresses' );
$same = is_array( $child );
foreach ( $before as $url => $html ) {
	$same = $same && ( $child[ $url ] ?? null ) === $html;
}
ok( $same, 'People off: every card, share page, contact file and the links page are the same as before' );

// A profile that is gone: that card falls back to its own details.
$gone = $GLOBALS['posts'][ $made[1] ];
unset( $GLOBALS['posts'][ $made[1] ] );
$fallback = render_all();
ok( $fallback['https://site.test/card/joy/'] === $before['https://site.test/card/joy/'], 'profile gone: the card uses its saved details, the same as before' );
$GLOBALS['posts'][ $made[1] ] = $gone;

// Card off in People: the card address answers "not found", the same as a profile page off.
$GLOBALS['meta'][ $made[1] ]['_scout_team']['shows']['card'] = false;
$S::flush();
ok( ! isset( $S::people()['joy'] ), 'Card off: Joy has no card' );
$GLOBALS['qv']       = array( 'scc_view' => 'card', 'scc_who' => 'joy' );
$GLOBALS['wp_query'] = new Fake_Query();
Scout_Cards_Routes::dispatch();
ok( $GLOBALS['wp_query']->is404 && 404 === $GLOBALS['status'], 'Card off: /card/joy/ answers 404' );
$GLOBALS['qv'] = array( 'scc_view' => 'vcard', 'scc_who' => 'joy' );
$GLOBALS['wp_query'] = new Fake_Query();
Scout_Cards_Routes::dispatch();
ok( $GLOBALS['wp_query']->is404, 'Card off: /card/joy/save/ answers 404 too' );
ok( false === strpos( render_all()['https://site.test/card/'], 'Joy McNamara' ), 'Card off: the main card no longer lists Joy' );
$GLOBALS['meta'][ $made[1] ]['_scout_team']['shows']['card'] = true;
$GLOBALS['posts'][ $made[1] ]->post_status = 'draft';
$S::flush();
ok( ! isset( $S::people()['joy'] ), 'Not live in People: no card' );
$GLOBALS['posts'][ $made[1] ]->post_status = 'publish';
$S::flush();
ok( isset( $S::people()['joy'] ), 'Card back on: the card is back at the same address' );

// Someone new in People with Card on gets a card.
$GLOBALS['posts'][150] = (object) array( 'ID' => 150, 'post_type' => 'scout_team', 'post_status' => 'publish', 'post_title' => 'Riley Park', 'post_name' => 'riley-park' );
$GLOBALS['meta'][150]['_scout_team'] = $T::shape( array( 'role' => 'Designer', 'card_phone' => '(919) 555-0123', 'shows' => array( 'card' => true, 'bio' => false, 'team' => false ) ) );
$S::flush();
$riley = $S::people()['riley-park'] ?? null;
ok( $riley && 'Designer' === $riley['title'] && '(919) 555-0123' === $riley['phone'], 'Card on in People: a new card at /card/riley-park/ with their card phone' );
ok( $riley && 'team@scoutraleigh.com' === $riley['email'], 'a blank card email uses the business email, as cards always have' );
ok( false !== strpos( Scout_Cards_VCard::build( $riley ), 'TEL;TYPE=WORK,VOICE:+19195550123' ), 'the contact file carries their card phone' );

// The card's switches.
$opt                                   = $GLOBALS['opts'][ $S::OPTION ];
$opt['people'][0]['show_phone']        = 0;
$opt['people'][0]['show_save']         = 0;
$opt['people'][0]['show_links']        = 1;
$opt['people'][0]['show_bio']          = 1;
$GLOBALS['opts'][ $S::OPTION ]         = $opt;
$GLOBALS['meta'][ $made[0] ]['_scout_team']['same_as'] = array( 'https://www.linkedin.com/in/sean', 'https://seanwrites.test/' );
$GLOBALS['meta'][ $made[0] ]['_scout_team']['bio']     = 'Sean leads strategy.';
$html = render_all()['https://site.test/card/sean/'];
ok( false === strpos( $html, 'tel:' ) && false === strpos( $html, 'Save my contact' ), 'switches: phone and Save contact off hide them' );
ok( isset( $S::people()['sean'] ) && 'https://site.test/card/sean/save/' === $S::people()['sean']['save'], 'switches: the contact file address keeps working' );
ok( false !== strpos( $html, 'aria-label="LinkedIn"' ) && false !== strpos( $html, 'aria-label="seanwrites.test"' ) && false !== strpos( $html, 'Sean leads strategy.' ), 'switches: profile links and the short bio show when on' );
ok( false === strpos( Scout_Cards_VCard::build( $S::people()['sean'] ), 'TEL' ), 'switches: phone off leaves the phone out of the contact file' );

// ---------- site 2: cards and People profiles together ----------
$GLOBALS['opts']  = array( 'scout_team_db' => 1 );
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['terms'] = array();
$GLOBALS['saw']   = array();
$GLOBALS['users'] = array( 7 => 'dana.lee@firm.test' );
$prof = static function ( $id, $name, $status, array $p, $thumb = 0, $team = true ) {
	$GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'scout_team', 'post_status' => $status, 'post_title' => $name, 'post_name' => sanitize_title( $name ) );
	$GLOBALS['meta'][ $id ]['_scout_team'] = Scout_Team::shape( $p );
	if ( $thumb ) {
		$GLOBALS['meta'][ $id ]['_thumbnail_id'] = $thumb;
	}
	if ( $team ) {
		$GLOBALS['terms'][ $id ] = array( 5 );
	}
};
$prof( 101, 'Dana Lee', 'publish', array( 'role' => 'Partner', 'email' => 'dana@firm.test', 'user' => 7 ) );
$prof( 102, 'Sam Ortiz', 'draft', array( 'email' => 'sam@firm.test' ) );
$prof( 103, 'Pat Kim', 'publish', array( 'email' => 'pat.kim@firm.test' ), 12 );
$prof( 104, 'Lee Ray', 'publish', array( 'role' => 'Paralegal', 'shows' => array( 'bio' => true, 'team' => true, 'card' => true ) ) );
$prof( 105, 'Ana Cruz', 'publish', array( 'role' => 'Associate', 'card_email' => 'ana.card@firm.test' ) );
$dana = array( 'name' => 'Dana Lee', 'slug' => 'dana', 'title' => 'Partner', 'email' => 'dana.lee@firm.test', 'intro' => 'Business cases.', 'pick' => '', 'tags' => '', 'photo_id' => 0, 'share_id' => 0 );
$sam  = array( 'name' => 'Sam Ortiz', 'slug' => 'sam', 'title' => 'Paralegal', 'email' => 'sam@firm.test', 'intro' => '', 'pick' => 'Scheduling.', 'tags' => 'Intake', 'photo_id' => 0, 'share_id' => 0 );
$pat  = array( 'name' => 'Pat Kim', 'slug' => 'pat', 'title' => 'Associate', 'email' => '', 'intro' => '', 'pick' => '', 'tags' => '', 'photo_id' => 12, 'share_id' => 0 );
$ana  = array( 'name' => 'Ana Cruz', 'slug' => 'ana', 'title' => 'Associate', 'email' => 'ana@firm.test', 'intro' => '', 'pick' => '', 'tags' => '', 'photo_id' => 0, 'share_id' => 0 );
$GLOBALS['opts'][ $S::OPTION ] = cards_option( array( $dana, $sam, $pat, $ana ) );
$before = render_all();
$C::maybe_upgrade();
$recs = $GLOBALS['opts'][ $S::OPTION ]['people'];
ok( 101 === $recs[0]['profile_id'], 'site 2: Dana matched by the login with her card email' );
ok( 103 === $recs[2]['profile_id'], 'site 2: Pat matched by exact name' );
ok( 105 === $recs[3]['profile_id'], 'site 2: Ana matched by exact name' );
ok( 102 !== $recs[1]['profile_id'] && 'publish' === $GLOBALS['posts'][ $recs[1]['profile_id'] ]->post_status, 'site 2: Sam\'s profile is a draft, so his live card got a new live profile' );
ok( array( 5 ) === ( $GLOBALS['terms'][ $recs[1]['profile_id'] ] ?? null ), 'site 2: Dana is already in Team, so the card list is the team and Sam\'s new profile joins Team' );
ok( 'dana.lee@firm.test' === $GLOBALS['meta'][101]['_scout_team']['card_email'] && 'dana@firm.test' === $GLOBALS['meta'][101]['_scout_team']['email'], 'site 2: Dana\'s card email filled in, her public email untouched' );
ok( 'Associate' === $GLOBALS['meta'][103]['_scout_team']['role'] && 12 === get_post_thumbnail_id( 103 ), 'site 2: Pat\'s missing title filled in, his photo already matched' );
ok( 'ana.card@firm.test' === $GLOBALS['meta'][105]['_scout_team']['card_email'], 'site 2: Ana\'s People card email is never overwritten' );
ok( false === $GLOBALS['meta'][104]['_scout_team']['shows']['card'] && true === $GLOBALS['meta'][104]['_scout_team']['shows']['bio'], 'site 2: Lee had Card on with no card, so Card is switched off and nothing else changes' );
$note = $C::take_note();
ok( false !== strpos( $note, 'Moved 4 card people into People.' ) && false !== strpos( $note, '3 already had a profile' ), 'site 2 note: moved 4, 3 already had a profile' );
ok( false !== strpos( $note, 'Ana Cruz (email)' ) && false !== strpos( $note, 'Lee Ray' ), 'site 2 note: names the card that now shows the People email, and Lee' );
$after = render_all();
ok( array_keys( $before ) === array_keys( $after ), 'site 2: the same addresses before and after' );
foreach ( array( 'https://site.test/card/', 'https://site.test/card/dana/', 'https://site.test/card/sam/', 'https://site.test/card/pat/', 'https://site.test/card/dana/save/', 'https://site.test/card/pat/share/', 'links' ) as $url ) {
	ok( $before[ $url ] === $after[ $url ], 'site 2 parity: ' . $url . ' is the same before and after' );
}
ok( $before['https://site.test/card/ana/'] !== $after['https://site.test/card/ana/'] && false !== strpos( $after['https://site.test/card/ana/'], 'ana.card@firm.test' ), 'site 2: Ana\'s card now shows the card email People already had (named in the note)' );

// ---------- the Cards screen saves card settings without losing anything ----------
require __DIR__ . '/../../scout-cards/includes/admin.php';
$save = new ReflectionMethod( 'Scout_Cards_Admin', 'save_people' );
$save->setAccessible( true );
$rows = array(
	array( 'rec' => 0, 'profile_id' => 101, 'key' => 'dana', 'slug' => 'dana', 'intro' => 'New intro.', 'pick' => '', 'tags' => 'Business', 'share_id' => 0, 'show_phone' => 1, 'show_email' => 0, 'show_links' => 0, 'show_bio' => 0, 'show_save' => 1 ),
	array( 'rec' => -1, 'profile_id' => 150, 'key' => 'riley-park', 'slug' => '', 'intro' => 'Hi.', 'show_phone' => 1, 'show_email' => 1, 'show_links' => 1, 'show_bio' => 0, 'show_save' => 1 ),
);
$saved = $save->invoke( null, $rows );
ok( 'Dana Lee' === $saved[0]['name'] && 'dana.lee@firm.test' === $saved[0]['email'] && 101 === $saved[0]['profile_id'], 'screen: saving keeps the card\'s old details and its profile' );
ok( 'New intro.' === $saved[0]['intro'] && 0 === $saved[0]['show_email'], 'screen: the card\'s own settings and switches save' );
ok( 4 === count( array_filter( $saved, static fn( $r ) => ! empty( $r['name'] ) ) ), 'screen: cards not on the form stay as they were' );
$last = end( $saved );
ok( 150 === $last['profile_id'] && 'riley-park' === $last['slug'] && 1 === $last['show_links'], 'screen: someone new in People gets a card record with their link name' );

$keep_words = $save->invoke( null, array( array( 'rec' => 0, 'profile_id' => 101, 'key' => 'dana', 'slug' => 'dana', 'share_id' => 0, 'show_phone' => 1 ) ) );
ok( 'Business cases.' === $keep_words[0]['intro'] && array_key_exists( 'pick', $keep_words[0] ) && array_key_exists( 'tags', $keep_words[0] ), 'screen: with People on the form sends no words, and the card keeps its own for the fallback' );

// ---------- move 2 on a site that already had move 1 ----------
ok( array( 'card_intro' => 'Card words.' ) === $C::words_to_copy( $T::shape( array() ), array( 'intro' => 'Card words.', 'pick' => '  ', 'tags' => '' ) ), 'words to copy: only what the card has' );
ok( array() === $C::words_to_copy( $T::shape( array( 'card_intro' => 'Mine.' ) ), array( 'intro' => 'Card words.' ) ), 'words to copy: a People value is never overwritten' );
$GLOBALS['opts']  = array( 'scout_team_db' => 1, 'scout_cards_people_db' => 1 );
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();
$GLOBALS['terms'] = array();
$prof( 301, 'Dana Lee', 'publish', array( 'role' => 'Partner', 'card_intro' => 'Written in People.', 'shows' => array( 'card' => true ) ) );
$prof( 302, 'Sam Ortiz', 'publish', array( 'role' => 'Paralegal', 'shows' => array( 'card' => true ) ) );
$GLOBALS['opts'][ $S::OPTION ] = cards_option(
	array(
		array( 'profile_id' => 301 ) + $dana + array( 'pick' => 'Business cases.', 'tags' => 'Contracts' ),
		array( 'profile_id' => 302 ) + $sam,
	)
);
$GLOBALS['opts'][ $S::OPTION ]['people'][0]['pick'] = 'Business cases.';
$GLOBALS['opts'][ $S::OPTION ]['people'][0]['tags'] = 'Contracts';
$before3 = render_all();
$people3 = $S::people();
ok( 'Written in People.' === $people3['dana']['intro'], 'site 3 before: a profile intro already wins over the card\'s' );
$C::maybe_upgrade();
ok( 2 === $GLOBALS['opts']['scout_cards_people_db'] && 0 === count( array_diff( array_keys( $GLOBALS['posts'] ), array( 301, 302 ) ) ), 'site 3: only move 2 ran (no new profiles)' );
ok( 'Written in People.' === $GLOBALS['meta'][301]['_scout_team']['card_intro'], 'site 3: Dana\'s People intro is never overwritten' );
ok( 'Business cases.' === $GLOBALS['meta'][301]['_scout_team']['card_why'] && 'Contracts' === $GLOBALS['meta'][301]['_scout_team']['card_tags'], 'site 3: her empty why and tags were filled from the card' );
ok( 'Scheduling.' === $GLOBALS['meta'][302]['_scout_team']['card_why'] && 'Intake' === $GLOBALS['meta'][302]['_scout_team']['card_tags'] && '' === $GLOBALS['meta'][302]['_scout_team']['card_intro'], 'site 3: Sam\'s why and tags copied, his empty intro stays empty' );
ok( 'Partner' === $GLOBALS['meta'][301]['_scout_team']['role'] && true === $GLOBALS['meta'][301]['_scout_team']['shows']['card'], 'site 3: nothing else on the profile changes' );
ok( false !== strpos( $C::take_note(), 'for 2 people now live on their People profiles' ), 'site 3: the note says the card words for 2 people moved' );
$after3 = render_all();
ok( array_keys( $before3 ) === array_keys( $after3 ), 'site 3: the same addresses before and after' );
$same3 = true;
foreach ( $before3 as $url => $html ) {
	$same3 = $same3 && $html === $after3[ $url ];
}
ok( $same3, 'site 3 parity: every card, share page, contact file and the links page are the same before and after move 2' );
$GLOBALS['meta'][302]['_scout_team']['card_why'] = 'Changed.';
$C::maybe_upgrade();
ok( 'Changed.' === $GLOBALS['meta'][302]['_scout_team']['card_why'], 'site 3: move 2 never runs twice' );

// ---------- the main card and Business info ----------
$GLOBALS['core_business'] = array( 'name' => 'Scout Media & Consulting', 'email' => 'team@scoutraleigh.com', 'logo_id' => 12, 'description' => 'Websites for law firms.' );
$opt = $GLOBALS['opts'][ $S::OPTION ];
$GLOBALS['opts'][ $S::OPTION ] = array_merge( $opt, array( 'name' => '', 'email' => '', 'photo_id' => 0, 'team_intro' => '' ) );
$S::flush();
$team = $S::people()['team'];
ok( 'Scout Media & Consulting' === $team['name'] && 'team@scoutraleigh.com' === $team['email'], 'main card: empty name and email use Business info' );
ok( 12 === $team['photo_id'] && 'https://site.test/img/12-medium.jpg' === $team['photo'], 'main card: no photo of its own uses the Business info logo' );
ok( 'Websites for law firms.' === $team['intro'], 'main card: an empty intro uses the Business info description' );
$GLOBALS['opts'][ $S::OPTION ] = array_merge( $opt, array( 'name' => 'Scout', 'email' => 'hello@scoutraleigh.com', 'photo_id' => 13, 'team_intro' => 'Our own words.' ) );
$S::flush();
$team = $S::people()['team'];
ok( 'Scout' === $team['name'] && 'hello@scoutraleigh.com' === $team['email'] && 13 === $team['photo_id'] && 'Our own words.' === $team['intro'], 'main card: "Use something else" values win' );
$GLOBALS['core_business'] = array( 'name' => 'Scout Media & Consulting', 'email' => 'team@scoutraleigh.com' );
$GLOBALS['opts'][ $S::OPTION ] = array_merge( $opt, array( 'photo_id' => 0, 'team_intro' => '' ) );
$S::flush();
$team = $S::people()['team'];
ok( 0 === $team['photo_id'] && 'Websites for firms that want results.' === $team['intro'], 'main card: with no Business info logo or description, the Site Icon and tagline stay as before' );

// ---------- the Cards screen ----------
$screen = static function () {
	Scout_Cards_Settings::flush();
	ob_start();
	Scout_Cards_Admin::render();
	return ob_get_clean();
};
$names = static function ( $html ) {
	preg_match_all( '/name="scout_cards_settings\[([a-z_]+)\]/', $html, $m );
	return array_count_values( $m[1] );
};
$GLOBALS['core_business'] = array( 'name' => 'Scout Media & Consulting', 'email' => 'team@scoutraleigh.com', 'description' => 'Websites for law firms.' );
$GLOBALS['opts'][ $S::OPTION ] = array_merge( $opt, array( 'name' => 'Scout Media & Consulting', 'email' => 'hello@scoutraleigh.com', 'team_intro' => '' ) );
$on = $screen();
$keys = array( 'name', 'phone', 'city', 'region', 'postal', 'footer', 'font', 'pick_heading', 'note', 'team_intro', 'links_intro', 'socials', 'email', 'mode', 'accent', 'photo_id', 'logo_id', 'share_card_id', 'share_links_id', 'spot_heading', 'spot_text', 'spot_label', 'spot_sub', 'spot_url', 'links_latest', 'enable_cards', 'enable_links', 'card_base', 'links_base', 'people', 'links' );
$got = $names( $on );
ok( array() === array_diff( $keys, array_keys( $got ) ), 'screen, People on: every saved setting is still on the form' );
ok( 1 === $got['name'] && 1 === $got['email'] && 1 === $got['team_intro'] && 1 === $got['pick_heading'], 'screen, People on: name, email, intro and the heading are on the form once' );
ok( false !== strpos( $on, 'From Business info: <strong>Scout Media &amp; Consulting</strong>' ) && false !== strpos( $on, 'From Business info: <strong>Websites for law firms.</strong>' ), 'screen, People on: the main card says what it takes from Business info' );
ok( 1 === preg_match( '#<details class="scc-else"><summary>Use something else</summary><label class="screen-reader-text" for="[^"]+">Name on the card</label><input class="regular-text" type="text" id="[^"]+" name="scout_cards_settings\[name\]" value="">#', $on ), 'screen, People on: a name the same as Business info shows as empty, with "Use something else" closed' );
ok( 1 === preg_match( '#<details class="scc-else" open><summary>Use something else</summary><label class="screen-reader-text" for="[^"]+">Email on the card</label><input class="regular-text" type="email" id="[^"]+" name="scout_cards_settings\[email\]" value="hello@scoutraleigh.com">#', $on ), 'screen, People on: an email of its own opens "Use something else" with it filled in' );
ok( false !== strpos( $on, '<details class="scc-fold"><summary>Extras</summary>' ) && false !== strpos( $on, 'Heading above the people' ), 'screen, People on: the heading and Spotlight sit under Extras' );
foreach ( array( 'Phone', 'Email', 'Intro', 'Tags', 'Profile links', 'Short bio', 'Save contact' ) as $label ) {
	ok( false !== strpos( $on, '> ' . $label . '</label>' ), 'screen, People on: a "' . $label . '" switch under Show on the card' );
}
ok( false === strpos( $on, '[people][0][intro]' ) && false === strpos( $on, '[people][0][pick]' ) && false === strpos( $on, '[people][0][tags]' ) && false === strpos( $on, '[people][0][name]' ) && false === strpos( $on, '[people][0][title]' ), 'screen, People on: no content fields per person' );
ok( false !== strpos( $on, 'name="scout_cards_settings[people][0][slug]"' ) && false !== strpos( $on, 'Link name' ) && false !== strpos( $on, '>Share mode</a>' ) && false !== strpos( $on, '<summary>QR code</summary>' ) && false !== strpos( $on, 'Edit in People</a>' ), 'screen, People on: each card has its link name, Share mode, QR code and Edit in People' );
ok( 1 === preg_match( '#<details class="scc-fold"><summary>More</summary><table class="form-table" role="presentation"><tr><th scope="row">Share picture</th>#', $on ) && false !== strpos( $on, 'name="scout_cards_settings[people][0][spot_heading]"' ), 'screen, People on: the share picture and Spotlight sit under each card\'s More' );
foreach ( array( 'Full name', 'Intro (on their card, in their voice)', 'Why talk to them (on the main card)', 'Main card words', 'Business name', 'Main email' ) as $gone ) {
	ok( false === strpos( $on, $gone ), 'screen, People on: "' . $gone . '" is gone' );
}

// Scout People off: the screen as it was.
$GLOBALS['opts']['scout_cards_people_db'] = 0;
$off = $screen();
$got = $names( $off );
ok( array() === array_diff( $keys, array_keys( $got ) ), 'screen, People off: every saved setting is on the form' );
foreach ( array( 'Card people', 'Full name', 'Intro (on their card, in their voice)', 'Why talk to them (on the main card)', 'Tags', 'Main card words', 'Heading above the people', 'Business name', 'Main email', 'Logo or photo' ) as $kept ) {
	ok( false !== strpos( $off, $kept ), 'screen, People off: "' . $kept . '" is still there' );
}
ok( false === strpos( $off, 'Use something else' ) && false === strpos( $off, '<summary>Extras</summary>' ) && false === strpos( $off, 'Show on the card' ), 'screen, People off: none of the new People parts show' );
ok( false !== strpos( $off, 'name="scout_cards_settings[people][0][intro]"' ), 'screen, People off: each person keeps their intro field' );
$GLOBALS['opts']['scout_cards_people_db'] = 2;
unset( $GLOBALS['core_business'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
