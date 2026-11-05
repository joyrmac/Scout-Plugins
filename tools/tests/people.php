<?php
// Scout People (scout-team): the move to People keeps live pages the same, credential
// lines need a source to show, card only contact never reaches the public, and
// "needs a look" names what is missing. Runs without WordPress:
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

require __DIR__ . '/../../scout-team/includes/class-scout-team.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-front.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-schema.php';
require __DIR__ . '/../../scout-team/includes/class-scout-team-changes.php';
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

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
