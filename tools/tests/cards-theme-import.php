<?php
// Scout Cards takes over the business cards from a theme that drew its own (the
// Scout Media theme). The one-time copy maps the theme's `scout_cards` settings
// into the plugin's own, never overwrites what the plugin already has, keeps a
// value that Business info already shows empty, adds each theme card person once,
// switches on the theme's own card look, and runs once (its own option). The
// plugin stands down only while the theme still draws cards (its Cards screen and
// its card builder both loaded).
// Runs without WordPress:
//   php tools/tests/cards-theme-import.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']    = array();
$GLOBALS['hooks']   = array();
$GLOBALS['filters'] = array();
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://site.test/wp-content/plugins/scout-cards/'; }
function plugin_basename( $f ) { return 'scout-cards/scout-cards.php'; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_action( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $prio ); return true; }
function add_filter( $h, $cb = null, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $prio ); return true; }
function apply_filters( $t, $v ) { return array_key_exists( $t, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $t ] : $v; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_hex_color( $c ) { return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $c ) ? $c : ''; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_bloginfo( $k = '' ) { return 'description' === $k ? 'Websites for firms.' : 'Scout Media & Consulting'; }
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function get_page_by_path( $p ) { return in_array( $p, array( 'card', 'links' ), true ) ? (object) array( 'ID' => 'card' === $p ? 302 : 303 ) : null; }
function gmdate_stub() { return '2026-11-05'; }

// Business info (Scout Core): the name and email are typed there.
function scout_core_business() { return array( 'name' => 'Scout Media & Consulting', 'email' => 'team@example.test', 'phone' => '(984) 325-9661' ); }
final class Scout_Core_Business {
	public static function get() { return array( 'name' => 'Scout Media & Consulting', 'phone' => '(984) 325-9661', 'email' => 'team@example.test', 'city' => 'Fuquay-Varina', 'region' => 'NC', 'postal' => '', 'country' => '', 'same_as' => '' ); }
}

$plugin = dirname( __DIR__, 2 ) . '/scout-cards/scout-cards.php';
require $plugin;

// ---------- the stand-down rule ----------
ok( false === scout_cards_theme_has_cards(), 'no theme cards: the plugin draws the cards' );
eval( 'function scout_cards_admin_page() {}' );
ok( false === scout_cards_theme_has_cards(), 'a theme Cards screen alone does not make the plugin stand down' );
eval( 'function scout_card_people() { return array(); }' );
ok( true === scout_cards_theme_has_cards(), 'the theme draws cards (screen and card builder loaded): the plugin stands down' );
$GLOBALS['filters']['scout_cards_theme_has_cards'] = false;
ok( false === scout_cards_theme_has_cards(), 'the filter can still decide' );
unset( $GLOBALS['filters']['scout_cards_theme_has_cards'] );

// ---------- the theme's settings, as Scout Media 1.53.9 saved them ----------
$theme = array(
	'phone'  => '(984) 325-9661',
	'org'    => 'Scout Media & Consulting',
	'city'   => 'Fuquay-Varina', 'region' => 'NC', 'zip' => '27526',
	'note'   => 'A note for the phone contact.',
	'footer' => 'Veteran owned · Fuquay-Varina, NC',
	'team'   => array(
		'name' => 'Scout Media', 'email' => 'team@example.test', 'intro' => 'Team intro.', 'pick_heading' => 'Who would you like to talk to?', 'photo_id' => 0,
		'spot_heading' => '', 'spot_text' => '', 'spot_label' => 'Scout Recon', 'spot_sub' => 'Save its card too.', 'spot_url' => 'https://recon.test/card/',
	),
	'people' => array(
		array( 'slug' => 'joy', 'name' => 'Joy McNamara', 'title' => 'Co-Founder', 'email' => 'joy@example.test', 'intro' => 'I design.', 'pick' => 'Talk to Joy.', 'tags' => 'Design, Build', 'photo_id' => 0 ),
		array( 'slug' => 'sean', 'name' => 'Sean McNamara', 'title' => 'Co-Founder', 'email' => 'sean@example.test', 'intro' => 'I lead.', 'pick' => 'Talk to Sean.', 'tags' => 'Strategy', 'photo_id' => 7, 'spot_heading' => 'Why I built it', 'spot_text' => 'Because.', 'spot_label' => 'Its card', 'spot_sub' => '', 'spot_url' => 'https://recon.test/card/' ),
		array( 'slug' => 'save', 'name' => 'Reserved' ),
		array( 'slug' => 'ghost', 'name' => '' ),
	),
	'links_intro'  => 'Links intro.',
	'links_latest' => 1,
	'links'        => array(
		array( 'label' => 'Book a free call', 'sub' => 'Pick a time', 'url' => '/book/', 'external' => 0 ),
		array( 'label' => '', 'sub' => '', 'url' => '/blank/', 'external' => 0 ),
		array( 'label' => 'Scout Recon', 'sub' => 'See it', 'url' => 'https://recon.test/', 'external' => 1 ),
	),
	'socials' => "https://www.facebook.com/x\nhttps://x.com/y",
);
$defaults = Scout_Cards_Settings::defaults();

// ---------- the mapping, on a site with nothing saved in the plugin ----------
$plan = Scout_Cards_Theme_Import::plan( $theme, array(), $defaults );
$s    = $plan['settings'];
ok( 'Scout Media' === $s['name'], 'the main card name comes over (it differs from Business info)' );
ok( 'Scout Media & Consulting' === $s['org'], 'the company name saved to phones comes over' );
ok( ! isset( $s['email'] ) && ! isset( $s['phone'] ), 'the email and phone match Business info, so they stay empty and keep following it' );
ok( ! isset( $s['city'] ) && ! isset( $s['region'] ), 'the city and state match Business info, so they stay empty' );
ok( '27526' === $s['postal'], 'the ZIP (empty in Business info) comes over' );
ok( 'Veteran owned · Fuquay-Varina, NC' === $s['footer'] && 'A note for the phone contact.' === $s['note'], 'the footer line and contact note come over' );
ok( 'Team intro.' === $s['team_intro'] && 'Links intro.' === $s['links_intro'], 'the main card and links page intros come over' );
ok( ! isset( $s['pick_heading'] ), 'a heading the plugin already shows stays empty' );
ok( 'Scout Recon' === $s['spot_label'] && 'https://recon.test/card/' === $s['spot_url'] && ! isset( $s['spot_heading'] ), 'the main card Spotlight comes over, its empty parts stay empty' );
ok( ! isset( $s['photo_id'] ), 'no team photo in the theme: none is set' );
ok( "https://www.facebook.com/x\nhttps://x.com/y" === $s['socials'], 'the social links the theme showed come over' );
ok( 2 === count( $s['links'] ) && '/book/' === $s['links'][0]['url'] && 1 === $s['links'][1]['external'], 'link rows come over in order, blank rows skipped' );
ok( ! isset( $s['links_latest'] ), 'Latest from the blog on matches the plugin, so it is not saved' );
ok( 'theme' === $s['look'], "the theme's own card look is switched on" );
ok( array( 'joy', 'sean' ) === array_column( $s['people'], 'slug' ), 'each theme card person comes over once; reserved and nameless ones do not' );
ok( 'I design.' === $s['people'][0]['intro'] && 'Talk to Joy.' === $s['people'][0]['pick'] && 'Design, Build' === $s['people'][0]['tags'], 'a person keeps their intro, why and tags' );
ok( 7 === $s['people'][1]['photo_id'] && 'Why I built it' === $s['people'][1]['spot_heading'], 'a person keeps their photo and Spotlight' );
ok( '' === $s['people'][0]['spot_url'] && ! isset( $s['people'][0]['profile_id'] ), 'no Spotlight stays empty, and People linking is left to the People move' );
ok( array( 'joy', 'sean' ) === $plan['people'], 'the plan names the people it added' );

// ---------- never overwriting ----------
$saved = array(
	'name'   => 'My own name',
	'footer' => 'My own footer',
	'look'   => '',
	'links'  => array(),
	'people' => array( array( 'slug' => 'joy', 'name' => 'Joy (plugin)', 'profile_id' => 41 ) ),
	'links_latest' => 1,
);
$plan2 = Scout_Cards_Theme_Import::plan( $theme, $saved, $defaults );
$s2    = $plan2['settings'];
ok( 'My own name' === $s2['name'] && 'My own footer' === $s2['footer'], 'values already saved in the plugin are kept' );
ok( array() === $s2['links'], 'link rows already saved (even none) are kept' );
ok( 'Joy (plugin)' === $s2['people'][0]['name'] && 41 === $s2['people'][0]['profile_id'], 'a person already there by link name is kept as it is' );
ok( array( 'joy', 'sean' ) === array_column( $s2['people'], 'slug' ) && array( 'sean' ) === $plan2['people'], 'only the missing person is added' );
ok( 'theme' === $s2['look'], "an empty look (the default) takes the theme's look" );
$plan3 = Scout_Cards_Theme_Import::plan( array( 'links_latest' => 0 ), array(), $defaults );
ok( 0 === $plan3['settings']['links_latest'], 'Latest from the blog switched off in the theme stays off' );

// ---------- source(): what the theme showed ----------
function scout_card_defaults() {
	return array( 'people' => array( array( 'slug' => 'sean', 'spot_heading' => 'Default heading', 'spot_text' => '', 'spot_label' => 'Default label', 'spot_sub' => '', 'spot_url' => 'https://recon.test/card/' ) ) );
}
function scout_card_settings() {
	return array( 'org' => 'Org', 'team' => array( 'name' => 'Team' ), 'people' => array( array( 'slug' => 'sean', 'name' => 'Sean' ), array( 'slug' => 'joy', 'name' => 'Joy', 'spot_heading' => '' ) ) );
}
function scout_social_links() { return '<nav><a href="https://www.facebook.com/p?id=1&amp;x=2" aria-label="Facebook"></a><a href="https://x.com/y"></a></nav>'; }
$src = Scout_Cards_Theme_Import::source();
ok( 'Default heading' === $src['people'][0]['spot_heading'] && 'Default label' === $src['people'][0]['spot_label'], 'a person saved before the Spotlight existed gets the theme default Spotlight, as the theme showed it' );
ok( '' === $src['people'][1]['spot_heading'] && '' === $src['people'][1]['spot_label'], 'a saved empty Spotlight stays hidden' );
ok( "https://www.facebook.com/p?id=1&x=2\nhttps://x.com/y" === $src['socials'], "the theme's social links are read from its icons" );

// ---------- the gate: once, recorded in its own option ----------
$GLOBALS['opts'] = array();
Scout_Cards_Settings::flush();
Scout_Cards_Theme_Import::maybe_run();
$rec = get_option( 'scout_cards_theme_import' );
ok( is_array( $rec ) && 1 === $rec['found'] && in_array( 'org', $rec['filled'], true ), 'the copy runs once the theme has stood down and records what it filled' );
ok( array( 302, 303 ) === $rec['pages'], "the theme's /card/ and /links/ Pages are remembered, so the Cards screen does not warn about them" );
ok( 'Team' === get_option( 'scout_cards_settings' )['name'], 'the plugin settings are written' );
ok( false !== strpos( (string) get_option( 'scout_cards_people_note' )['theme'], 'Copied the business cards from the theme' ), 'the Cards screen says once that the cards came from the theme' );
update_option( 'scout_cards_settings', array( 'name' => 'Changed later' ) );
Scout_Cards_Theme_Import::maybe_run();
ok( 'Changed later' === get_option( 'scout_cards_settings' )['name'], 'the copy never runs twice' );

// ---------- the theme's own card look ----------
Scout_Cards_Settings::flush();
update_option( 'scout_cards_settings', array( 'look' => 'theme' ) );
ok( true === Scout_Cards_Render_Theme::on(), "the theme's look is on while the theme still carries it" );
update_option( 'scout_cards_settings', array( 'look' => '' ) );
ok( false === Scout_Cards_Render_Theme::on(), 'the Scout Cards look otherwise' );
ok( 'Org name' === Scout_Cards_Settings::org( array( 'org' => 'Org name', 'name' => 'Name' ) ) && 'Name' === Scout_Cards_Settings::org( array( 'org' => '', 'name' => 'Name' ) ), 'the company name saved to phones falls back to the card name' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit( $fail ? 1 : 0 );
