<?php
// Scout Core: searches that found nothing. Runs without WordPress:
//   php tools/tests/core-empty-search.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['admin']      = false;
$GLOBALS['logged_in']  = false;
$GLOBALS['can_edit']   = false;
$GLOBALS['autoload']   = array();
function add_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; $GLOBALS['autoload'][ $k ] = $a; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $cap ) { return $GLOBALS['can_edit']; }
function wp_date( $f ) { return $GLOBALS['today'] ?? gmdate( $f ); }
require __DIR__ . '/../../scout-core/includes/seo/class-seo-empty-searches.php';
$E = 'Scout_SEO_Empty_Searches';

/** A main query as WordPress builds it. */
final class Fake_Query {
	public $posts = array();
	public $found_posts = 0;
	public $vars = array();
	public $main = true;
	public $search = true;
	public $paged = false;
	public $feed = false;
	public function __construct( $s, array $set = array() ) { $this->vars['s'] = $s; foreach ( $set as $k => $v ) { $this->$k = $v; } }
	public function is_main_query() { return $this->main; }
	public function is_search() { return $this->search; }
	public function is_paged() { return $this->paged; }
	public function is_feed() { return $this->feed; }
	public function get( $k ) { return $this->vars[ $k ] ?? ''; }
}
function visitor( $ip = '203.0.113.5', $agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ) {
	$_SERVER['REMOTE_ADDR']     = $ip;
	$_SERVER['HTTP_USER_AGENT'] = $agent;
}
function reset_site() { $GLOBALS['opts'] = array(); $GLOBALS['transients'] = array(); }

// Normalizing.
ok( 'brazilian blowout cost' === $E::normalize( "  Brazilian   BLOWOUT\tcost  " ), 'lowercased, trimmed, one space between words' );
ok( 80 === mb_strlen( $E::normalize( str_repeat( 'abcdefghij', 5 ) . ' ' . str_repeat( 'klmnopqrst', 5 ) ) ), 'cut to 80 characters' );
ok( 'curly cut' === $E::normalize( '<b>curly</b> cut' ), 'tags are dropped' );
ok( 'café hours' === $E::normalize( 'CAFÉ Hours' ), 'letters outside English are lowercased too' );
ok( '' === $E::normalize( '   ' ) && '' === $E::normalize( '!!! ??? ...' ), 'empty or punctuation only is not kept' );

// Personal data and junk.
ok( '' === $E::normalize( 'jane@example.com' ), 'an email address is not kept' );
ok( '' === $E::normalize( 'call 9195550100' ), 'seven or more digits in a row are not kept' );
ok( '' === $E::normalize( '(919) 555-0100' ) && '' === $E::normalize( '919.555.0100' ) && '' === $E::normalize( '919 555 0100' ), 'a phone number is not kept, however it is written' );
ok( 'case 123456' === $E::normalize( 'case 123456' ), 'six digits are kept' );
ok( '' === $E::normalize( 'https://example.com/x' ) && '' === $E::normalize( 'www.example.org' ) && '' === $E::normalize( 'go to mysite.com' ), 'web addresses are not kept' );
ok( '' === $E::normalize( 'one two three four five six seven eight nine' ), 'more than 8 words is not kept' );
ok( 'one two three four five six seven eight' === $E::normalize( 'one two three four five six seven eight' ), 'eight words are kept' );

// Counting.
$list = array();
$list = $E::add( $list, 'gray coverage', '2026-11-01' );
$list = $E::add( $list, 'gray coverage', '2026-11-03' );
$list = $E::add( $list, 'parking', '2026-11-02' );
ok( 2 === $list['gray coverage']['n'] && '2026-11-01' === $list['gray coverage']['first'] && '2026-11-03' === $list['gray coverage']['last'], 'a repeat search adds to the count and moves the last day seen' );
ok( array( 'n', 'first', 'last' ) === array_keys( $list['parking'] ), 'each phrase keeps only a count and two days, nothing about who searched' );
ok( array( 'gray coverage', 'parking' ) === array_keys( $E::top( $list ) ), 'the report lists the most searched first' );

// The cap.
$big = array();
for ( $i = 0; $i < 200; $i++ ) {
	$big[ 'phrase ' . $i ] = array( 'n' => 5, 'first' => '2026-10-01', 'last' => '2026-10-20' );
}
$big['phrase 7']['n']    = 1;
$big['phrase 7']['last'] = '2026-10-02';
$big['phrase 8']['n']    = 1;
$big['phrase 8']['last'] = '2026-10-30';
$capped = $E::add( $big, 'brand new search', '2026-11-04' );
ok( 200 === count( $capped ), 'the list holds 200 phrases at most' );
ok( isset( $capped['brand new search'] ), 'a new search still gets on a full list' );
ok( ! isset( $capped['phrase 7'] ) && isset( $capped['phrase 8'] ), 'the least searched, oldest phrase is dropped first' );
ok( 200 === count( $E::add( $capped, 'phrase 0', '2026-11-04' ) ), 'a repeat on a full list drops nothing' );
ok( 20 === count( $E::top( $capped ) ), 'the report shows the top 20' );

// The 90-day window.
$old = array(
	'fresh'   => array( 'n' => 1, 'first' => '2026-11-01', 'last' => '2026-11-01' ),
	'edge'    => array( 'n' => 3, 'first' => '2026-08-01', 'last' => '2026-08-07' ),
	'too old' => array( 'n' => 9, 'first' => '2026-07-01', 'last' => '2026-08-06' ),
);
$kept = $E::prune( $old, '2026-11-05' );
ok( isset( $kept['fresh'], $kept['edge'] ) && ! isset( $kept['too old'] ), 'a phrase not searched for more than 90 days is removed' );
$GLOBALS['opts'][ $E::OPTION ] = $old;
$GLOBALS['today'] = '2026-11-05';
$E::prune_saved();
ok( array( 'fresh', 'edge' ) === array_keys( $GLOBALS['opts'][ $E::OPTION ] ), 'the daily check saves the pruned list' );
$GLOBALS['opts'][ $E::OPTION ] = array( 'too old' => $old['too old'] );
$E::prune_saved();
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'a list with nothing left is deleted' );

// Recording a real request.
reset_site();
visitor();
$E::record( new Fake_Query( 'Keratin Treatment' ) );
ok( 1 === ( $GLOBALS['opts'][ $E::OPTION ]['keratin treatment']['n'] ?? 0 ), 'an empty search from a visitor is counted' );
ok( false === $GLOBALS['autoload'][ $E::OPTION ], 'the list is not loaded on every page' );
$E::record( new Fake_Query( 'keratin   treatment' ) );
ok( 1 === $GLOBALS['opts'][ $E::OPTION ]['keratin treatment']['n'], 'the same visitor searching again within 30 minutes counts once' );
$stored = json_encode( $GLOBALS['opts'] ) . json_encode( array_keys( $GLOBALS['transients'] ) );
ok( false === strpos( $stored, '203.0.113.5' ) && false === stripos( $stored, 'iphone' ), 'no IP address or browser is stored anywhere' );
visitor( '198.51.100.9' );
$E::record( new Fake_Query( 'keratin treatment' ) );
ok( 2 === $GLOBALS['opts'][ $E::OPTION ]['keratin treatment']['n'], 'another visitor counts again' );
$E::record( new Fake_Query( 'call me 919-555-0100' ) );
ok( 1 === count( $GLOBALS['opts'][ $E::OPTION ] ), 'a phone number typed into search is not recorded' );

// Nothing is recorded for admins, bots or other queries.
reset_site();
visitor( '192.0.2.1' );
$GLOBALS['logged_in'] = true;
$GLOBALS['can_edit']  = true;
$E::record( new Fake_Query( 'admin search' ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'nothing is recorded for a logged-in editor' );
$GLOBALS['can_edit'] = false;
$E::record( new Fake_Query( 'member search' ) );
ok( isset( $GLOBALS['opts'][ $E::OPTION ]['member search'] ), 'a logged-in visitor who cannot edit is counted' );
$GLOBALS['logged_in'] = false;
reset_site();
$GLOBALS['admin'] = true;
$E::record( new Fake_Query( 'dashboard search' ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'nothing is recorded inside the dashboard' );
$GLOBALS['admin'] = false;
$E::record( new Fake_Query( 'not search', array( 'search' => false ) ) );
$E::record( new Fake_Query( 'side query', array( 'main' => false ) ) );
$E::record( new Fake_Query( 'page two', array( 'paged' => true ) ) );
$E::record( new Fake_Query( 'feed', array( 'feed' => true ) ) );
$E::record( new Fake_Query( 'has results', array( 'posts' => array( 1 ), 'found_posts' => 1 ) ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'nothing is recorded for a non-search, side, page two, feed or found query' );
visitor( '192.0.2.2', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
$E::record( new Fake_Query( 'bot search' ) );
visitor( '192.0.2.3', '' );
$E::record( new Fake_Query( 'no agent' ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'nothing is recorded for a bot or a request with no browser named' );
$E::record( null );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'no query, nothing recorded' );

// The switch.
reset_site();
visitor( '192.0.2.4' );
ok( $E::on() && $E::on_in( array() ), 'the list is on by default' );
$E::record( new Fake_Query( 'before off' ) );
ok( isset( $GLOBALS['opts'][ $E::OPTION ] ), 'recorded while on' );
$GLOBALS['opts']['scout_seo_defaults'] = array( 'empty_searches' => '0' );
$E::settings_changed( array(), $GLOBALS['opts']['scout_seo_defaults'] );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'turning the switch off clears the list' );
visitor( '192.0.2.5' );
$E::record( new Fake_Query( 'while off' ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'nothing is recorded while the switch is off' );
$GLOBALS['opts'][ $E::OPTION ] = array( 'left over' => array( 'n' => 1, 'first' => '2026-11-05', 'last' => '2026-11-05' ) );
$E::prune_saved();
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'the daily check clears a list left while the switch is off' );
$GLOBALS['opts'][ $E::OPTION ] = array( 'x' => array( 'n' => 1, 'first' => '2026-11-05', 'last' => '2026-11-05' ) );
$E::settings_added( 'scout_seo_defaults', array( 'empty_searches' => '0' ) );
ok( ! isset( $GLOBALS['opts'][ $E::OPTION ] ), 'the first save of Search settings with the switch off clears it too' );
$GLOBALS['opts']['scout_seo_defaults'] = array( 'empty_searches' => '1' );
$E::settings_changed( array(), $GLOBALS['opts']['scout_seo_defaults'] );
visitor( '192.0.2.6' );
$E::record( new Fake_Query( 'back on' ) );
ok( isset( $GLOBALS['opts'][ $E::OPTION ]['back on'] ), 'turned back on, it records again' );

// The switch is saved with Core's other Search settings.
function esc_url_raw( $u ) { return (string) $u; }
function sanitize_textarea_field( $s ) { return (string) $s; }
require __DIR__ . '/../../scout-core/includes/seo/seo-settings.php';
$GLOBALS['opts']['scout_seo_defaults'] = array( 'default_image' => 'https://firm.test/x.jpg' );
$saved = Scout_Core_SEO_Settings::sanitize( array( 'default_image' => 'https://firm.test/x.jpg', 'empty_searches' => '0' ) );
ok( '0' === $saved['empty_searches'], 'the form turns it off' );
$saved = Scout_Core_SEO_Settings::sanitize( array( 'empty_searches' => '1' ) );
ok( '1' === $saved['empty_searches'], 'the form turns it on' );
$GLOBALS['opts']['scout_seo_defaults'] = array( 'empty_searches' => '0' );
ok( '0' === Scout_Core_SEO_Settings::sanitize( array() )['empty_searches'], 'a save without the field keeps it off' );
$GLOBALS['opts']['scout_seo_defaults'] = array();
ok( '1' === Scout_Core_SEO_Settings::sanitize( array() )['empty_searches'], 'and a site that never set it stays on' );

echo $fail ? "\n$fail FAILED\n" : "\nAll searches-that-found-nothing checks passed\n";
exit( $fail ? 1 : 0 );
