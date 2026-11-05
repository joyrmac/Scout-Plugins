<?php
// Scout People (scout-team): the move to People keeps live pages the same, credential
// lines need a source to show, the business card fields (card phone, card email,
// intro, why talk to them, tags) never reach the public,
// "needs a look" names what is missing, Add a person saves like the edit screen,
// Send them a link is hashed, single use, expiring and never touches the
// live profile until approved, "Written by" credits people with or without a
// login and changes nothing when empty, and the REST API hides what visitors
// may not see. Runs without WordPress:
//   php tools/tests/people.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );

// The few WordPress functions the code under test reaches.
$GLOBALS['meta']  = array();
$GLOBALS['opts']  = array();
$GLOBALS['thumb'] = array();
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters( $t, $v ) { return $v; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return esc_html( $s ); }
function esc_html_e( $s ) { echo esc_html( $s ); }
function esc_attr_e( $s ) { echo esc_attr( $s ); }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $s ) : ''; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_list_pluck( $list, $field ) { return array_map( static fn( $r ) => is_object( $r ) ? $r->$field : $r[ $field ], (array) $list ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? 'publish'; }
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumb'][ $id ] ?? 0; }
function has_post_thumbnail( $id ) { return ! empty( $GLOBALS['thumb'][ $id ] ); }
function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? 'Dana Lee'; }
function get_permalink( $id ) { return 'https://firm.test/team/' . ( isset( $GLOBALS['titles'][ $id ] ) ? strtolower( str_replace( ' ', '-', $GLOBALS['titles'][ $id ] ) ) : 'dana-lee' ) . '/'; }
function get_bloginfo( $k ) { return 'Firm Site'; }
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function get_the_modified_date( $f, $id ) { return '2026-11-05T00:00:00+00:00'; }
// For Add a person and Send them a link.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); }
$GLOBALS['transients'] = array();
$GLOBALS['updated']    = array();
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
function add_query_arg( $k, $v = null, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_current_user_id() { return 1; }
function current_user_can( $cap ) { return $GLOBALS['caps'][ $cap ] ?? true; }
function get_taxonomy( $t ) { return null; }
function esc_attr__( $s ) { return esc_attr( $s ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_nonce_field( $a, $n, $r = true, $echo = true ) { return '<input type="hidden" name="' . $n . '" value="nonce">'; }
function wp_date( $f, $t ) { return gmdate( 'Y-m-d', (int) $t ); }
function wp_update_post( $a ) { $GLOBALS['updated'][] = $a; return $a['ID']; }
function wp_attachment_is_image( $id ) { return 77 === (int) $id; }
function set_post_thumbnail( $id, $att ) { $GLOBALS['thumb'][ $id ] = $att; return true; }
function wp_get_post_parent_id( $id ) { return $GLOBALS['parent'][ $id ] ?? 0; }
function wp_delete_attachment( $id, $force = false ) { $GLOBALS['deleted'][] = $id; return true; }
function get_edit_post_link( $id, $c = 'display' ) { return 'https://firm.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
// For Written by and the REST API.
class WP_Post { public $ID = 0; public $post_type = 'post'; public $post_status = 'publish'; public $post_author = 0; public function __construct( array $f = array() ) { foreach ( $f as $k => $v ) { $this->$k = $v; } } }
class WP_Error { public $code; public $data; public function __construct( $code = '', $msg = '', $data = array() ) { $this->code = $code; $this->data = $data; } }
class Fake_Response { private $d; public function __construct( array $d ) { $this->d = $d; } public function get_data() { return $this->d; } public function set_data( $d ) { $this->d = $d; } }
$GLOBALS['wp_posts'] = array();
function get_post( $id = null ) { return null === $id ? ( $GLOBALS['current_post'] ?? null ) : ( $GLOBALS['wp_posts'][ (int) $id ] ?? null ); }
function get_post_type( $id ) { return $GLOBALS['types'][ (int) $id ] ?? ( isset( $GLOBALS['meta'][ (int) $id ]['_scout_team'] ) ? 'scout_team' : 'post' ); }
function get_post_field( $f, $id ) { return isset( $GLOBALS['wp_posts'][ (int) $id ] ) ? $GLOBALS['wp_posts'][ (int) $id ]->$f : ''; }
function is_admin() { return ! empty( $GLOBALS['is_admin'] ); }
function is_author() { return ! empty( $GLOBALS['is_author'] ); }
function in_the_loop() { return ! empty( $GLOBALS['in_loop'] ); }
function is_singular( $t = '' ) { return isset( $GLOBALS['singular'] ) && ( '' === $t || in_array( $GLOBALS['singular'], (array) $t, true ) ); }
function get_queried_object_id() { return $GLOBALS['queried'] ?? 0; }
function count_user_posts( $user, $types = 'post', $public = false ) { $n = 0; foreach ( $GLOBALS['wp_posts'] as $p ) { if ( (int) $p->post_author === (int) $user && in_array( $p->post_type, (array) $types, true ) && 'publish' === $p->post_status ) { $n++; } } return $n; }
function get_posts( $a ) {
	$out = array();
	foreach ( $GLOBALS['meta'] as $id => $m ) {
		if ( isset( $a['meta_key'] ) && ( ! isset( $m[ $a['meta_key'] ] ) || ( isset( $a['meta_value'] ) && $m[ $a['meta_key'] ] !== $a['meta_value'] ) ) ) {
			continue;
		}
		$out[] = $id;
	}
	return $out;
}

require __DIR__ . '/../../scout-team/includes/class-scout-team.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-front.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-schema.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-changes.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-editor.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-link.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-add.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-byline.php';
$T = 'Scout_Team';

// A profile saved before People: no Shows on, no sources, memberships as plain lines.
$old = array(
	'role'        => 'Partner',
	'bio'         => 'Dana handles business cases.',
	'email'       => 'dana@firm.test',
	'phone'       => '(919) 555-0101',
	'user'        => 7,
	'attorney'    => 1,
	'memberships' => array( 'Wake County Bar Association' ),
	'education'   => array( array( 'school' => 'UNC School of Law', 'degree' => 'JD', 'year' => '2008' ) ),
	'awards'      => array( array( 'name' => 'Pro Bono Award', 'year' => '2019', 'issuer' => 'NC Bar' ) ),
	'bar'         => array( array( 'state' => 'North Carolina', 'year' => '2008', 'number' => '12345', 'url' => '' ) ),
);

// Upgrade defaults keep today's pages: profile page and team page on, byline on with a login.
$up = $T::upgrade_profile( $old );
ok( true === $up['shows']['bio'] && true === $up['shows']['team'], 'an existing profile keeps its profile page and its team page spot' );
ok( true === $up['shows']['byline'], 'an existing profile with a login keeps its byline' );
ok( false === $up['shows']['card'] && false === $up['shows']['speaker'] && false === $up['shows']['chat'], 'card, speakers page and chat start off' );
$no_login = $T::upgrade_profile( array( 'user' => 0 ) );
ok( false === $no_login['shows']['byline'] && true === $no_login['shows']['bio'], 'without a login the byline starts off, the page stays on' );
ok( $up === $T::upgrade_profile( $up ), 'running the move twice changes nothing' );

// Legacy credential lines keep showing.
ok( $T::LEGACY_SOURCE === $up['education'][0]['source'] && $T::LEGACY_SOURCE === $up['awards'][0]['source'] && $T::LEGACY_SOURCE === $up['bar'][0]['source'], 'every line saved before sources is marked "Added before sources were tracked"' );
ok( array( array( 'name' => 'Wake County Bar Association', 'source' => $T::LEGACY_SOURCE ) ) === $up['memberships'], 'plain membership lines become lines with a source, nothing lost' );
$pub = $T::public_profile( $T::shape( $old ) );
ok( 1 === count( $pub['education'] ) && 1 === count( $pub['awards'] ) && 1 === count( $pub['memberships'] ) && 1 === count( $pub['bar'] ), 'legacy lines are all on the public profile' );
ok( array() === $T::look_reasons( $T::shape( $old ), true, 'publish', true ), 'a live legacy profile with a photo and alt text needs no look' );

// A new line without a source is saved, hidden, and flagged.
$in                  = $old;
$in['shows']         = array( '_sent' => '1', 'bio' => '1', 'team' => '1', 'byline' => '1' );
$in['memberships']   = array( array( 'name' => 'Wake County Bar Association', 'source' => $T::LEGACY_SOURCE ) );
$in['education'][0]['source'] = $T::LEGACY_SOURCE;
$in['education'][]   = array( 'school' => 'Duke University', 'degree' => 'BA', 'year' => '2005', 'source' => '' );
$in['awards'][0]['source'] = 'https://www.ncbar.org/awards';
$in['bar'][0]['source']    = 'https://www.ncbar.gov/member-directory';
$saved = $T::sanitize_profile( $in );
ok( 2 === count( $saved['education'] ) && '' === $saved['education'][1]['source'], 'a new line with no source is saved' );
$pub = $T::public_profile( $T::shape( $saved ) );
ok( 1 === count( $pub['education'] ) && 'UNC School of Law' === $pub['education'][0]['school'], 'and stays off the public profile' );
ok( 1 === $T::unsourced( $T::shape( $saved ) ), 'and is counted as missing a source' );
$why = $T::look_reasons( $T::shape( $saved ), true, 'publish', true );
ok( isset( $why['source'] ) && '1 line needs a source' === $why['source'], 'needs a look says "1 line needs a source"' );
ok( false === $saved['shows']['card'] && true === $saved['shows']['bio'], 'an unticked switch saves as off' );

// Card only contact never reaches the public profile, the page, or the schema.
$saved2               = $T::sanitize_profile( $in + array( 'card_phone' => '(919) 555-0199', 'card_email' => 'dana.card@firm.test' ) );
ok( '(919) 555-0199' === $saved2['card_phone'] && 'dana.card@firm.test' === $saved2['card_email'], 'card only phone and email are saved' );
$pub = $T::public_profile( $T::shape( $saved2 ) );
ok( ! array_key_exists( 'card_phone', $pub ) && ! array_key_exists( 'card_email', $pub ), 'the public profile has no card only contact' );
$GLOBALS['meta'][5][ $T::META ] = $saved2;
$GLOBALS['opts']['scout_team']  = array( 'contact' => 1, 'law' => 'on' ); // Email and phone shown on the page.
$person = json_encode( Scout_Team_Schema::person( 5 ) );
ok( false === strpos( $person, '555-0199' ) && false === strpos( $person, 'dana.card@' ), 'the Person schema has no card only contact' );
ok( false !== strpos( $person, 'dana@firm.test' ) && false !== strpos( $person, '555-0101' ), 'the regular email and phone are still in the schema' );
ok( false === strpos( $person, 'Duke University' ) && false !== strpos( $person, 'UNC School of Law' ), 'the schema leaves out the line with no source' );
ok( false === strpos( $person, 'ncbar.org/awards' ) && false === strpos( $person, $T::LEGACY_SOURCE ), 'sources themselves are never printed' );
$html = Scout_Team_Front::profile_html( 5 );
ok( false === strpos( $html, '555-0199' ) && false === strpos( $html, 'dana.card@' ), 'the profile page has no card only contact' );

// The Business card box: intro, why talk to them and tags, stored in the profile like the rest.
ok( array( 'card_intro', 'card_why', 'card_tags' ) === array_values( array_intersect( array_keys( $T::empty_profile() ), array( 'card_intro', 'card_why', 'card_tags' ) ) ) && '' === $T::shape( array() )['card_intro'], 'a profile has card intro, why and tags, empty to start' );
$card_in = $in + array(
	'card_phone' => '(919) 555-0199',
	'card_email' => 'dana.card@firm.test',
	'card_intro' => "I help owners <b>sell</b> their business.\nCall me first.",
	'card_why'   => 'Selling or buying a business.',
	'card_tags'  => 'Contracts, <i>M&A</i>, Leases',
);
$GLOBALS['meta'][12][ $T::META ] = Scout_Team_Editor::store( 12, $card_in, null );
$stored = $GLOBALS['meta'][12][ $T::META ];
ok( "I help owners sell their business.\nCall me first." === $stored['card_intro'] && 'Selling or buying a business.' === $stored['card_why'] && 'Contracts, M&A, Leases' === $stored['card_tags'], 'the edit screen saves intro, why and tags through the same clean and store path' );
ok( '(919) 555-0199' === $stored['card_phone'] && 'dana.card@firm.test' === $stored['card_email'], 'the card phone and email save in the same box' );
$pub = $T::public_profile( $T::shape( $stored ) );
ok( ! array_intersect( array( 'card_phone', 'card_email', 'card_intro', 'card_why', 'card_tags' ), array_keys( $pub ) ), 'public_profile() never has the card phone, email, intro, why or tags' );
ok( array( 'card_phone', 'card_email', 'card_intro', 'card_why', 'card_tags' ) === $T::card_keys() && ! array_diff( $T::card_keys(), Scout_Team_Front::rest_private_keys() ), 'the REST API keeps every card field back too' );
$GLOBALS['meta'][12][ $T::META ]['shows']['bio'] = true;
$html = Scout_Team_Front::profile_html( 12 );
$person = json_encode( Scout_Team_Schema::person( 12 ) );
ok( false === strpos( $html, 'Call me first' ) && false === strpos( $html, 'Selling or buying' ) && false === strpos( $html, 'Leases' ), 'the profile page shows none of the card words' );
ok( false === strpos( $person, 'Call me first' ) && false === strpos( $person, 'Selling or buying' ) && false === strpos( $person, 'Leases' ) && false === strpos( $person, '555-0199' ), 'and neither does the Person schema' );
ob_start();
Scout_Team_Editor::render_card( new WP_Post( array( 'ID' => 12, 'post_type' => 'scout_team' ) ) );
$box = ob_get_clean();
ok( false !== strpos( $box, 'name="scout_team[card_intro]"' ) && false !== strpos( $box, 'name="scout_team[card_why]"' ) && false !== strpos( $box, 'name="scout_team[card_tags]"' ) && false !== strpos( $box, 'name="scout_team[card_phone]"' ) && false !== strpos( $box, 'name="scout_team[card_email]"' ), 'the Business card box has intro, why, tags, card phone and card email' );
ok( false !== strpos( $box, 'Selling or buying a business.' ) && false !== strpos( $box, 'Contracts, M&amp;A, Leases' ), 'and is filled in with what is saved' );
ob_start();
Scout_Team_Editor::render_shows( new WP_Post( array( 'ID' => 12, 'post_type' => 'scout_team' ) ) );
ok( false === strpos( ob_get_clean(), 'card_phone' ), 'the card phone and email are no longer in the Shows on box' );
ok( false !== strpos( $html, 'dana@firm.test' ) && false !== strpos( $html, 'Wake County Bar Association' ), 'the profile page still shows the regular email and the membership' );
ok( false === strpos( $html, 'Duke University' ) && false === strpos( $html, $T::LEGACY_SOURCE ), 'the profile page shows no unsourced line and no source text' );

// Profile page off: no url in the schema.
$off                    = $saved2;
$off['shows']['bio']    = false;
$GLOBALS['meta'][6][ $T::META ] = $off;
ok( ! isset( Scout_Team_Schema::person( 6 )['url'] ), 'with the profile page off, the Person has no url to a page visitors cannot open' );
ok( isset( Scout_Team_Schema::person( 5 )['url'] ), 'with it on, the url is there as before' );

// Needs a look.
$GLOBALS['status'][8] = 'draft';
$GLOBALS['meta'][8][ $T::META ] = $old;
$why = $T::needs_look( 8 );
ok( isset( $why['photo'], $why['draft'] ) && 'No photo' === $why['photo'] && 'Not live yet' === $why['draft'], 'a draft with no photo: "No photo" and "Not live yet"' );
$GLOBALS['thumb'][9] = 90;
$GLOBALS['meta'][9][ $T::META ] = $old;
ok( array( 'alt' => 'Photo needs alt text' ) === $T::needs_look( 9 ), 'a photo with no alt text is flagged' );
$GLOBALS['meta'][90]['_wp_attachment_image_alt'] = 'Dana Lee';
ok( array() === $T::needs_look( 9 ), 'with alt text set, nothing to look at' );
$why = $T::look_reasons( $T::shape( $saved ), false, 'pending' );
ok( array( 'photo', 'source', 'draft' ) === array_keys( $why ), 'reasons come in order: photo, source, live' );

// Site log lines.
ok( 'Profile published: Dana Lee' === Scout_Team_Changes::title( 'publish', 'draft', 'Dana Lee' ), 'Site log: published' );
ok( 'Profile updated: Dana Lee' === Scout_Team_Changes::title( 'publish', 'publish', 'Dana Lee' ), 'Site log: updated' );
ok( 'Profile taken off the site: Dana Lee' === Scout_Team_Changes::title( 'draft', 'publish', 'Dana Lee' ), 'Site log: taken off the site' );
ok( '' === Scout_Team_Changes::title( 'draft', 'auto-draft', 'Dana Lee' ), 'Site log: saving a draft adds nothing' );

// Add a person: the walkthrough saves through the edit screen's own code, with the same defaults.
$A = 'Scout_Team_Add';
ok( $A::form_shows() === $T::default_shows( 0 ), 'the walkthrough starts with the plugin\'s own Shows on defaults' );
ok( Scout_Team_Editor::starts_in( 'team' ) && ! Scout_Team_Editor::starts_in( 'speakers' ), 'a new person starts in the Team group only' );
$form = array( 'role' => 'Paralegal', 'bio' => 'Sam helps clients.', 'user' => '0', 'shows' => array( '_sent' => '1' ) );
foreach ( $A::form_shows() as $key => $on ) {
	if ( $on ) {
		$form['shows'][ $key ] = '1';
	}
}
$walk = Scout_Team_Editor::store( 20, $A::raw( $form, true ), null );
ok( $walk['shows'] === $T::default_shows( 0 ), 'saved untouched, the walkthrough keeps the defaults: profile page and team page on, the rest off' );
Scout_Team_Editor::store( 21, $form, null );
ok( $GLOBALS['meta'][20][ $T::META ] === $GLOBALS['meta'][21][ $T::META ], 'the walkthrough and the edit screen store the same profile in the same meta value' );
$no_shows = Scout_Team_Editor::store( 22, $A::raw( array( 'role' => 'Partner' ), true ), null );
ok( $no_shows['shows'] === $T::default_shows( 0 ), 'with no switches sent at all, the plugin defaults apply' );
$with_login = $form;
$with_login['user'] = '7';
$byline = Scout_Team_Editor::store( 23, $A::raw( $with_login, true ), null );
ok( $byline['shows'] === $T::default_shows( 7 ) && true === $byline['shows']['byline'], 'picking "Writes posts as" turns the byline on, the same as the default for a linked login' );
$js = Scout_Team_Editor::store( 24, $A::raw( $with_login, false ), null );
ok( false === $js['shows']['byline'], 'when the script ran, the byline box as left is what saves' );

// Send them a link: random token, stored hashed, ends after 14 days, works once.
$L     = 'Scout_Team_Link';
$tok   = $L::new_token();
ok( $L::well_formed( $tok ) && $tok !== $L::new_token(), 'a token is 48 random hex characters, new each time' );
$url   = $L::create( 30 );
$token = substr( $url, strrpos( $url, '=' ) + 1 );
ok( 'https://firm.test/?scout_people=' . $token === $url, 'the link is the home page with ?scout_people=<token>' );
ok( $GLOBALS['meta'][30][ $L::HASH ] === $L::hash( $token ) && $GLOBALS['meta'][30][ $L::HASH ] !== $token && false === strpos( serialize( $GLOBALS['meta'][30] ), $token ), 'only a hash of the token is stored, never the token' );
ok( 'ok' === $L::verify( 30, $token ), 'the token opens its link' );
ok( 'wrong' === $L::verify( 30, $L::new_token() ) && 'wrong' === $L::verify( 30, 'sent' ) && 'wrong' === $L::verify( 31, $token ), 'another token, a bad one or another person\'s link: no' );
$ends = (int) $GLOBALS['meta'][30][ $L::META ]['expires'];
ok( $ends - time() >= 14 * DAY_IN_SECONDS - 5 && $ends - time() <= 14 * DAY_IN_SECONDS, 'it ends 14 days after it is made' );
ok( 'ok' === $L::verify( 30, $token, $ends - 1 ) && 'expired' === $L::verify( 30, $token, $ends ), 'and stops working then' );
$L::consume( 30 );
ok( 'used' === $L::verify( 30, $token ) && ! isset( $GLOBALS['meta'][30][ $L::HASH ] ), 'after one send it is used up, and its hash is gone' );
$again = $L::create( 30 );
ok( 'ok' === $L::verify( 30, substr( $again, strrpos( $again, '=' ) + 1 ) ) && 'wrong' === $L::verify( 30, $token ), 'a new link works and the old one does not' );
$L::revoke( 30 );
ok( 'wrong' === $L::verify( 30, substr( $again, strrpos( $again, '=' ) + 1 ) ), 'a link turned off stops working' );
ok( true === $L::allow_try( $tok ) && 1 === (int) get_transient( 'scout_team_tries_' . substr( $L::hash( $tok ), 0, 32 ) ), 'each send on a link is counted' );
for ( $i = 1; $i < $L::TRIES; $i++ ) {
	$L::allow_try( $tok );
}
ok( false === $L::allow_try( $tok ), 'too many sends in an hour are refused' );

// The page they fill in never offers card only contact, email or phone.
ok( ! array_intersect( array( 'card_phone', 'card_email', 'email', 'phone', 'user', 'shows' ), $L::fields() ), 'the link page takes no card only contact, email, phone, login or Shows on' );
ok( array() === array_diff( array( 'intro', 'why', 'tags' ), $L::fields() ), 'the link page takes the card intro, why and tags' );
$GLOBALS['meta'][40][ $T::META ] = $saved2;
$page = $L::form_html( 40, $L::prefill( 40 ), array(), $tok, true );
ok( false === strpos( $page, 'card_' ) && false === strpos( $page, '555-0199' ) && false === strpos( $page, 'dana.card@' ), 'the form has no card only fields and shows no card contact' );
ok( false === strpos( $page, '[phone]' ) && false === strpos( $page, '[email]' ) && false === strpos( $page, 'dana@firm.test' ), 'and no email or phone field' );
ok( false !== strpos( $page, 'name="st_website"' ) && false !== strpos( $page, 'name="_st_details"' ), 'it carries a honeypot field and a nonce' );
ok( false !== strpos( $page, 'name="st[education][0][source]"' ) && false !== strpos( $page, 'UNC School of Law' ), 'credentials come with a source field, filled in with what is there now' );
$sent = $L::clean(
	array(
		'name'        => 'Dana Lee-Park',
		'role'        => 'Managing Partner <b>',
		'bio'         => 'New bio.',
		'card_phone'  => '(919) 555-0000',
		'card_email'  => 'x@firm.test',
		'email'       => 'x@firm.test',
		'phone'       => '(919) 555-0000',
		'user'        => 3,
		'shows'       => array( 'card' => 1 ),
		'education'   => array( array( 'school' => 'Duke University', 'degree' => 'BA', 'year' => '2005', 'source' => 'https://duke.edu/alumni' ) ),
		'same_as'     => "https://www.linkedin.com/in/danalee\nhttps://www.avvo.com/dana",
	),
	false
);
ok( array( 'name', 'role', 'bio', 'education', 'awards', 'memberships', 'same_as' ) === array_keys( $sent ), 'what they send is cut down to the page\'s own fields' );
ok( 'Managing Partner' === $sent['role'] && 2 === count( $sent['same_as'] ) && 'https://duke.edu/alumni' === $sent['education'][0]['source'], 'and cleaned the same way as the edit screen' );

// What they send waits. The live profile changes only on approve.
$live_before = $GLOBALS['meta'][40][ $T::META ];
$L::save_pending( 40, $sent, 77 );
ok( $GLOBALS['meta'][40][ $T::META ] === $live_before, 'sending details never touches the live profile' );
ok( 'Partner' === $T::profile( 40 )['role'] && 'Partner' === $T::public_profile( $T::profile( 40 ) )['role'], 'the profile page and the schema still read the live title' );
ok( isset( $T::needs_look( 40 )['pending'] ) && 'Sent their details, waiting for your OK' === $T::needs_look( 40 )['pending'], 'the person needs a look: "Sent their details, waiting for your OK"' );
$diff = $L::changes( 40 );
ok( array( 'Partner', 'Managing Partner' ) === $diff['Title'] && array( 'Dana Lee', 'Dana Lee-Park' ) === $diff['Name'] && isset( $diff['Education'] ), 'the review table lists each field that would change, now and sent' );
$todo = $L::inbox( array() );
ok( 1 === count( $todo ) && 'people-details-40' === $todo[0]['id'] && 'Sent their details, waiting for your OK' === $todo[0]['label'], 'and there is one To do item for them' );
ok( $L::approve( 40 ), 'approve runs' );
$after = $T::profile( 40 );
ok( 'Managing Partner' === $after['role'] && 'New bio.' === $after['bio'] && 'Duke University' === $after['education'][0]['school'] && 2 === count( $after['same_as'] ), 'approve copies what they sent into the real fields' );
ok( '(919) 555-0199' === $after['card_phone'] && 'dana@firm.test' === $after['email'] && 7 === (int) $after['user'] && $after['shows'] === $saved2['shows'], 'and leaves card contact, email, the login and Shows on alone' );
ok( 77 === $GLOBALS['thumb'][40] && 'Dana Lee-Park' === end( $GLOBALS['updated'] )['post_title'], 'the photo becomes their photo and the name is updated' );
ok( ! isset( $GLOBALS['meta'][40][ $T::PENDING ] ) && ! isset( $T::needs_look( 40 )['pending'] ), 'then nothing is waiting' );
$GLOBALS['meta'][41][ $T::META ] = $saved2;
$GLOBALS['parent'][78]           = 41;
$L::save_pending( 41, $sent, 78 );
ok( $L::discard( 41 ) && $GLOBALS['meta'][41][ $T::META ] === $saved2 && ! isset( $GLOBALS['meta'][41][ $T::PENDING ] ), 'discard deletes what they sent and leaves the profile as it was' );
ok( in_array( 78, $GLOBALS['deleted'] ?? array(), true ), 'and the photo they uploaded with it' );
$keep = $L::merge( $T::shape( $saved2 ), array( 'role' => '', 'bio' => '  ' ) );
ok( 'Partner' === $keep['role'] && $saved2['bio'] === $keep['bio'], 'an empty title or bio keeps what is there' );

// With A business card on, the link page takes their card words too, as pending changes.
$card_on                          = $stored;
$card_on['shows']['card']         = true;
$GLOBALS['meta'][42][ $T::META ]  = $card_on;
$pre  = $L::prefill( 42 );
$page = $L::form_html( 42, $pre, array(), $tok, false );
ok( false !== strpos( $page, 'name="st[intro]"' ) && false !== strpos( $page, 'name="st[why]"' ) && false !== strpos( $page, 'name="st[tags]"' ) && false !== strpos( $page, 'Your business card' ), 'Card on: the form has their card intro, why and tags' );
ok( false !== strpos( $page, 'Selling or buying a business.' ) && false !== strpos( $page, 'Contracts, M&amp;A, Leases' ), 'filled in with what is there now' );
ok( false === strpos( $page, 'card_phone' ) && false === strpos( $page, 'card_email' ) && false === strpos( $page, '555-0199' ) && false === strpos( $page, 'dana.card@' ) && false === strpos( $page, '[phone]' ) && false === strpos( $page, '[email]' ), 'and still no card phone, card email, email or phone' );
$off_page = $L::form_html( 40, $L::prefill( 40 ), array(), $tok, false );
ok( false === strpos( $off_page, 'name="st[intro]"' ), 'Card off: no card fields on the form' );
$sent2 = $L::clean( array( 'name' => 'Dana Lee', 'intro' => 'New card intro <b>here</b>.', 'why' => 'Ask about leases.', 'tags' => 'Leases, Contracts', 'card_phone' => '(919) 555-0000', 'card_email' => 'x@firm.test' ) );
ok( 'New card intro here.' === $sent2['card_intro'] && 'Ask about leases.' === $sent2['card_why'] && 'Leases, Contracts' === $sent2['card_tags'], 'the card words they send are cleaned like the edit screen' );
ok( ! isset( $sent2['card_phone'] ) && ! isset( $sent2['card_email'] ) && ! isset( $sent2['intro'] ), 'and the card phone and email they try to send are dropped' );
$L::save_pending( 42, $sent2, 0 );
ok( $GLOBALS['meta'][42][ $T::META ] === $card_on && 'Selling or buying a business.' === $T::profile( 42 )['card_why'], 'sending card words never touches the live profile' );
$diff = $L::changes( 42 );
ok( isset( $diff['Card intro'], $diff['Why talk to them'], $diff['Card tags'] ) && array( 'Selling or buying a business.', 'Ask about leases.' ) === $diff['Why talk to them'], 'the review table lists the card words, now and sent' );
ok( $L::approve( 42 ), 'approve runs' );
$p42 = $T::profile( 42 );
ok( 'New card intro here.' === $p42['card_intro'] && 'Ask about leases.' === $p42['card_why'] && 'Leases, Contracts' === $p42['card_tags'], 'approve copies the card words into the profile' );
ok( '(919) 555-0199' === $p42['card_phone'] && 'dana.card@firm.test' === $p42['card_email'], 'and leaves the card phone and email alone' );
$keep = $L::merge( $T::shape( $card_on ), array( 'card_intro' => '', 'card_why' => ' ' ) );
ok( $card_on['card_intro'] === $keep['card_intro'] && $card_on['card_why'] === $keep['card_why'] && $card_on['card_tags'] === $keep['card_tags'], 'an empty card line, or one not sent, keeps what is there' );

// ---------- Written by: credit people with or without a login ----------
$F = 'Scout_Team_Front';
$B = 'Scout_Team_Byline';
$profile = static function ( array $over ) use ( $T ) {
	return $T::sanitize_profile( array_merge( array( 'role' => 'Writer', 'bio' => 'Writes.', 'shows' => array( '_sent' => '1', 'bio' => '1', 'byline' => '1' ) ), $over ) );
};
$GLOBALS['titles'] = array( 101 => 'Ana Ruiz', 102 => 'Ben Cole', 103 => 'Cal Diaz', 104 => 'Dee Fox' );
$GLOBALS['meta'][101][ $T::META ] = $profile( array() );                                                   // A guest writer, no login.
$GLOBALS['meta'][102][ $T::META ] = $profile( array( 'shows' => array( '_sent' => '1', 'byline' => '1' ) ) ); // Own page off.
$GLOBALS['meta'][103][ $T::META ] = $profile( array( 'shows' => array( '_sent' => '1', 'bio' => '1' ) ) );   // Byline off.
$GLOBALS['meta'][104][ $T::META ] = $profile( array( 'user' => 9 ) );                                      // Linked to login 9.
$post = static function ( int $id, int $author, array $picked = array() ) use ( $T ) {
	$GLOBALS['wp_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_author' => $author ) );
	$GLOBALS['types'][ $id ]    = 'post';
	if ( $picked ) {
		$GLOBALS['meta'][ $id ][ $T::AUTHORS ] = $picked;
	}
};
$post( 500, 9 );                      // By Dee's login, nobody picked.
$post( 501, 3, array( 101 ) );        // Staff wrote it for Ana.
$post( 502, 3, array( 101, 102, 104 ) );
$post( 503, 9, array( 103 ) );        // Cal's byline is off.
$post( 504, 9, array( 101 ) );        // Dee's login, written for Ana.
$post( 505, 3 );                      // Staff, nobody picked.
$post( 506, 3, array( 102 ) );        // Ben's page is off.
$post( 507, 9, array( 104 ) );        // Dee's login, Dee picked too.
$on = static function ( int $id ) {
	$GLOBALS['current_post']    = $GLOBALS['wp_posts'][ $id ];
	$GLOBALS['authordata']      = (object) array( 'ID' => $GLOBALS['wp_posts'][ $id ]->post_author );
};

ok( 'Dana' === $T::join_names( array( 'Dana' ) ) && 'Dana & Sam' === $T::join_names( array( 'Dana', 'Sam' ) ) && 'Dana, Sam & Lee' === $T::join_names( array( 'Dana', 'Sam', 'Lee' ) ), 'names join with ", " and " & " before the last' );
ok( array( 4, 2 ) === $T::clean_ids( array( '4', 0, -1, 'x', 2, 4 ) ) && array() === $T::clean_ids( '' ), 'picked IDs are whole numbers, each once, in order' );
ok( array( 101, 104 ) === $B::picked( array( '101' ), '104' ) && array( 101 ) === $B::picked( array( '101' ), '101' ) && array() === $B::picked( array(), '' ), 'the classic box keeps the ticked people in order, then the one picked to add' );

// Empty: nothing changes.
$on( 505 );
ok( 'Staff Person' === $F::written_by_name( 'Staff Person' ) && 'Staff Person' === $F::written_by_display_name( 'Staff Person', 3 ), 'nobody picked: the author name is what WordPress gave' );
ok( 'https://firm.test/author/staff/' === $F::written_by_link( 'https://firm.test/author/staff/', 3 ), 'nobody picked: the author link is what WordPress gave' );
ok( '<a href="https://firm.test/author/staff/">Staff Person</a>' === $F::written_by_posts_link( '<a href="https://firm.test/author/staff/">Staff Person</a>' ), 'nobody picked: the author posts link is untouched' );
ok( array() === $T::byline_people( 505 ), 'nobody picked and no linked login: byline_people() is empty' );
$on( 500 );
ok( 'Dee Login' === $F::written_by_name( 'Dee Login' ) && 'https://firm.test/author/dee/' === $F::written_by_link( 'https://firm.test/author/dee/', 9 ), 'a linked login with nobody picked keeps today\'s output (the linked login filter handles it)' );
ok( array( 104 ) === $T::byline_people( 500 ), 'byline_people() falls back to the profile linked to the post\'s login' );

// One person.
$on( 501 );
ok( 'Ana Ruiz' === $F::written_by_name( 'Staff Person' ) && 'Ana Ruiz' === $F::written_by_display_name( 'Staff Person', 3 ), 'one person picked: their name replaces the author\'s' );
ok( 'https://firm.test/team/ana-ruiz/' === $F::written_by_link( 'https://firm.test/author/staff/', 3 ), 'and the author link goes to their profile page' );
ok( '<a href="https://firm.test/team/ana-ruiz/" rel="author">Ana Ruiz</a>' === $F::written_by_posts_link( 'x' ), 'and the author posts link names and links them' );
ok( array( 101 ) === $T::byline_people( 501 ), 'byline_people() returns the picked person' );
ok( 'Someone Else' === $F::written_by_display_name( 'Someone Else', 12 ) && 'https://firm.test/author/x/' === $F::written_by_link( 'https://firm.test/author/x/', 12 ), 'a theme asking about another login gets that login, untouched' );

// Several people.
$on( 502 );
ok( 'Ana Ruiz, Ben Cole & Dee Fox' === $F::written_by_name( 'Staff Person' ), 'several people: "Ana Ruiz, Ben Cole & Dee Fox"' );
ok( 'https://firm.test/team/ana-ruiz/' === $F::written_by_link( 'x', 3 ), 'the author link is the first person\'s profile' );
ok( '<a href="https://firm.test/team/ana-ruiz/" rel="author">Ana Ruiz</a>, Ben Cole &amp; <a href="https://firm.test/team/dee-fox/" rel="author">Dee Fox</a>' === $F::written_by_posts_link( 'x' ), 'each name links to its own page; a page that is off shows a plain name' );
ok( array( 101, 102, 104 ) === $T::byline_people( 502 ), 'byline_people() keeps the order picked' );

// A link only when the profile page is on.
$on( 506 );
ok( 'Ben Cole' === $F::written_by_name( 'Staff Person' ) && '' === $F::written_by_link( 'https://firm.test/author/staff/', 3 ), 'first person\'s page off: their name, and no address' );
ok( 'Ben Cole' === $F::written_by_posts_link( 'x' ), 'and the author posts link is their name with no link' );

// Byline off: left out, and the post falls back to today.
$on( 503 );
ok( 'Dee Login' === $F::written_by_name( 'Dee Login' ) && array() === $T::written_by( 503 ), 'a picked person with their byline off is left out' );
ok( array( 104 ) === $T::byline_people( 503 ), 'with nobody left, byline_people() falls back to the linked login' );

// Where it never applies.
$on( 501 );
$GLOBALS['is_admin'] = true;
ok( 'Staff Person' === $F::written_by_name( 'Staff Person' ), 'admin screens keep the WordPress author' );
$GLOBALS['is_admin']  = false;
$GLOBALS['is_author'] = true;
ok( 'Staff Person' === $F::written_by_name( 'Staff Person' ), 'an author archive\'s own title stays about that login' );
$GLOBALS['in_loop'] = true;
ok( 'Ana Ruiz' === $F::written_by_name( 'Staff Person' ), 'but each post in the archive loop shows its picked people' );
$GLOBALS['is_author'] = $GLOBALS['in_loop'] = false;
$GLOBALS['opts']['scout_team']['bylines'] = 0;
ok( 'Staff Person' === $F::written_by_name( 'Staff Person' ) && 'x' === $F::written_by_link( 'x', 3 ), 'Bylines off in People settings: nothing changes' );
$GLOBALS['opts']['scout_team']['bylines'] = 1;
$GLOBALS['wp_posts'][501]->post_type = 'page';
ok( 'Staff Person' === $F::written_by_name( 'Staff Person' ), 'a post type without the box keeps the WordPress author' );
$GLOBALS['wp_posts'][501]->post_type = 'post';

// Schema: the author is the existing Person @id, one record per person.
$article = array( 'business' => array( '@id' => 'https://firm.test/#business' ), 'article' => array( '@type' => 'BlogPosting', 'author' => array( '@type' => 'Person', 'name' => 'Staff Person' ) ) );
$g = Scout_Team_Schema::with_authors( $article, array() );
ok( $g === $article, 'nobody to credit: the article schema is untouched' );
$g = Scout_Team_Schema::with_authors( $article, array( 101 ) );
ok( array( '@id' => 'https://firm.test/team/ana-ruiz/#person' ) === $g['article']['author'] && 'https://firm.test/team/ana-ruiz/#person' === $g['person_101']['@id'], 'one person: the author is their Person @id, and their Person is in the graph' );
$g = Scout_Team_Schema::with_authors( $article + array( 'person_101' => array( '@id' => 'https://firm.test/team/ana-ruiz/#person', 'kept' => 1 ) ), array( 101, 102 ) );
ok( array( array( '@id' => 'https://firm.test/team/ana-ruiz/#person' ), array( '@id' => 'https://firm.test/team/ben-cole/#person' ) ) === $g['article']['author'], 'several people: a list of Person @ids, in order' );
ok( 1 === $g['person_101']['kept'] && 1 === count( array_filter( array_keys( $g ), static fn( $k ) => 'person_101' === $k ) ) && ! isset( $g['person_102']['url'] ), 'a Person already in the graph is never added twice; a page that is off has no url' );
$GLOBALS['singular'] = 'post';
$GLOBALS['queried']  = 505;
ok( Scout_Team_Schema::extend_graph( $article ) === $article, 'the graph for a post with nobody picked and no linked login is unchanged' );
$GLOBALS['queried'] = 500;
$g = Scout_Team_Schema::extend_graph( $article );
ok( array( '@id' => 'https://firm.test/team/dee-fox/#person' ) === $g['article']['author'] && isset( $g['person_104'] ), 'a linked login with nobody picked: the same Person author as before' );
$GLOBALS['queried'] = 502;
$g = Scout_Team_Schema::extend_graph( $article );
ok( 3 === count( $g['article']['author'] ) && isset( $g['person_101'], $g['person_102'], $g['person_104'] ), 'picked people become the article\'s authors in the schema' );
unset( $GLOBALS['singular'], $GLOBALS['queried'] );

// The People row count: picked posts plus their login's posts, each once.
ok( 3 === $B::count_for( 101 ), 'Ana: picked on three posts' );
ok( 2 === $B::count_for( 102 ), 'Ben: picked on two posts (his page being off does not matter)' );
ok( 0 === $B::count_for( 103 ), 'Cal: byline off, no posts' );
ok( 4 === $B::count_for( 104 ), 'Dee: picked on one post, plus her login\'s posts that nobody else took, each once' );
ok( 2 === $B::tally( 7, 5, 2, array( 1 => array( 5, array( 7 ) ), 2 => array( 3, array( 8 ) ) ) ), 'tally: a post by the login with them picked counts once' );

// ---------- the REST API ----------
$GLOBALS['caps']['edit_posts'] = false; // A visitor.
$args = $F::rest_query( array( 'post_type' => 'scout_team', 'post_status' => 'any' ) );
ok( 'publish' === $args['post_status'] && in_array( 6, $args['post__not_in'], true ) && in_array( 102, $args['post__not_in'], true ) && ! in_array( 101, $args['post__not_in'], true ), 'REST list for visitors: live only, people whose page is off left out' );
$args = $F::rest_query( array( 'post__in' => array( 6, 101 ) ) );
ok( array( 101 ) === $args['post__in'], 'asking for IDs directly still leaves out a hidden person' );
ok( array( 0 ) === $F::rest_query( array( 'post__in' => array( 6 ) ) )['post__in'], 'asking only for a hidden person finds nobody' );
ok( in_array( 102, $F::rest_search( array( 's' => 'Ben' ) )['post__not_in'], true ), 'the REST search leaves out hidden people too' );
$hidden = $F::rest_prepare( new Fake_Response( array( 'id' => 102 ) ), new WP_Post( array( 'ID' => 102, 'post_type' => 'scout_team' ) ) );
ok( $hidden instanceof WP_Error && 404 === $hidden->data['status'], 'one hidden person by ID: "not found"' );
$draft = $F::rest_prepare( new Fake_Response( array( 'id' => 101 ) ), new WP_Post( array( 'ID' => 101, 'post_type' => 'scout_team', 'post_status' => 'draft' ) ) );
ok( $draft instanceof WP_Error, 'a draft: "not found"' );
$res = $F::rest_prepare(
	new Fake_Response( array( 'id' => 101, 'title' => 'Ana Ruiz', 'card_email' => 'a@x.test', 'meta' => array( '_scout_team' => array( 'card_phone' => '1' ), '_scout_team_pending' => array( 'bio' => 'x' ), '_scout_team_link_hash' => 'h', 'card_phone' => '1', 'other' => 'ok' ) ) ),
	new WP_Post( array( 'ID' => 101, 'post_type' => 'scout_team' ) )
);
$d = $res->get_data();
ok( 'Ana Ruiz' === $d['title'] && ! isset( $d['card_email'] ) && array( 'other' => 'ok' ) === $d['meta'], 'a visible person: card only contact, waiting details and link data never go out' );
$GLOBALS['caps']['edit_posts'] = true; // An editor.
$args = array( 'post_type' => 'scout_team', 'post_status' => 'any' );
ok( $F::rest_query( $args ) === $args && $F::rest_search( $args ) === $args, 'editors and the block editor see every person, as before' );
$full = new Fake_Response( array( 'id' => 102, 'meta' => array( 'card_phone' => '1' ) ) );
ok( $F::rest_prepare( $full, new WP_Post( array( 'ID' => 102, 'post_type' => 'scout_team' ) ) ) === $full, 'and get each one as WordPress sends it' );
unset( $GLOBALS['caps'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
