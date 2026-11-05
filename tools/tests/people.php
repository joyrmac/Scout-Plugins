<?php
// Scout People (scout-team): the move to People keeps live pages the same, credential
// lines need a source to show, card only contact never reaches the public,
// "needs a look" names what is missing, Add a person saves like the edit screen,
// and Send them a link is hashed, single use, expiring and never touches the
// live profile until approved. Runs without WordPress:
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
function get_the_title( $id ) { return 'Dana Lee'; }
function get_permalink( $id ) { return 'https://firm.test/team/dana-lee/'; }
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
function current_user_can( $cap ) { return true; }
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

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
