<?php
// Stub harness for Scout Forms Guard's content check (layer 4).
// Run: php tools/tests/forms-guard-content.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['t'] = array( 'filters' => array(), 'blocked' => 0 );
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $tag, $value, ...$a ) { return isset( $GLOBALS['t']['filters'][ $tag ] ) ? $GLOBALS['t']['filters'][ $tag ] : $value; }
function do_action( ...$a ) { $GLOBALS['t']['blocked']++; }
function home_url() { return 'https://walterlutzelectric.com/'; }
function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) {}
function wp_date( $f ) { return gmdate( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_basename( $f ) { return basename( $f ); }
function register_activation_hook( ...$a ) {}
function is_admin() { return false; }
require dirname( __DIR__, 2 ) . '/scout-forms-guard/scout-forms-guard.php';
$fail = 0;
function ok( $c, $m ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $m . "\n"; if ( ! $c ) { $fail++; } }
$spam = array(
	"Hey Add walterlutzelectric.com in Google's Search Index to appear in online search results! Add walterlutzelectric.com today: domainsubmit.pro",
	"Hello Feature walterlutzelectric.com in Google's Search Index so it can be displayed in Google search results! Register walterlutzelectric.com here: searchindex.pro",
	'Great site, check https://cheap-leads.biz for more customers',
	'Visit www.example.net now',
);
$ham = array(
	'Need a 200 amp panel upgrade at our house in Crystal Lake. Email me at bob.smith@gmail.com',
	'Outlets in the kitchen stopped working after a storm. Call after 5.',
	'Saw you on walterlutzelectric.com, can you quote a hot tub circuit?',
	'Cart is a 2019 Club Car, battery dies after 2 holes.',
	'Price was $1.50 per foot? Want 3.5 hours of work.',
);
foreach ( $spam as $s ) { ok( Scout_Forms_Guard::looks_like_spam( $s ), 'spam: ' . substr( $s, 0, 50 ) ); }
foreach ( $ham as $h ) { ok( ! Scout_Forms_Guard::looks_like_spam( $h ), 'real: ' . substr( $h, 0, 50 ) ); }
ok( true === Scout_Forms_Guard::content_spam( false, array( 'id' => 1 ), array( '1' => 'Willie', '3' => $spam[0], 'source_url' => 'https://walterlutzelectric.com/quote/' ) ), 'entry with the pitch is marked spam' );
ok( 1 === $GLOBALS['t']['blocked'], 'counted as blocked' );
ok( false === Scout_Forms_Guard::content_spam( false, array( 'id' => 1 ), array( '1' => 'Ann', '3' => $ham[0], 'source_url' => 'https://other.com/' ) ), 'real entry passes (source_url ignored)' );
$GLOBALS['t']['filters']['scout_forms_guard_content_check'] = false;
ok( false === Scout_Forms_Guard::content_spam( false, array( 'id' => 1 ), array( '3' => $spam[0] ) ), 'filter turns it off' );
echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
