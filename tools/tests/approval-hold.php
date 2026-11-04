<?php
// Scout Build approval hold: live sites wait for Scout's OK, staging never does,
// security fixes skip the wait. Runs without WordPress:
//   php tools/tests/approval-hold.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WP_PLUGIN_DIR', __DIR__ . '/no-plugins' );
$GLOBALS['opts']     = array();
$GLOBALS['live']     = true;
$GLOBALS['operator'] = true;
$GLOBALS['catalog']  = array( 'hold' => array(), 'security' => array() );
$GLOBALS['log']      = array();
$GLOBALS['released'] = array(); // slug|version => unix time.
$GLOBALS['updates']  = (object) array( 'response' => array() );
$GLOBALS['themes']   = (object) array( 'response' => array() );
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return 'scout_hub_catalog' === $k ? $GLOBALS['catalog'] : false; }
function get_site_transient( $k ) { return 'update_plugins' === $k ? $GLOBALS['updates'] : $GLOBALS['themes']; }
function wp_date( $f, $t ) { return gmdate( 'M j', $t ); }
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_get_current_user() { return new class() { public $display_name = 'Joy McNamara'; public function exists() { return true; } }; }
function wp_get_theme( $sheet ) {
	return new class( $sheet ) {
		public $sheet;
		public function __construct( $s ) { $this->sheet = $s; }
		public function exists() { return in_array( $this->sheet, array( 'firm', 'twentytwentyfive' ), true ); }
		public function get( $k ) { return 'Name' === $k ? 'Firm' : '1.0.0'; }
	};
}
final class Scout_Hub_Health { public static function is_live() { return $GLOBALS['live']; } public static function is_staging() { return ! $GLOBALS['live']; } }
final class Scout_Hub_Access { public static function is_operator() { return $GLOBALS['operator']; } }
final class Scout_Hub_Log { public static function add( array $e ) { $GLOBALS['log'][] = $e; return 1; } }
final class Scout_Hub_Themes { public static function source( $theme ) { return 'firm' === $theme->sheet ? array( 'repo' => 'Scout-Media-Raleigh/Firm' ) : null; } }
final class Scout_Hub_Admin { public static function url( $t ) { return 'admin.php?page=scout-build-' . $t; } }
final class Scout_Hub_Catalog {
	const REPO        = 'Scout-Media-Raleigh/Scout-Plugins';
	const CATALOG_KEY = 'scout_hub_catalog';
	public static function features() { return array(); }
	public static function holds() { return $GLOBALS['catalog']['hold']; }
	public static function published( $slug, $version ) { return $GLOBALS['released'][ "$slug|$version" ] ?? 0; }
}
require __DIR__ . '/../../scout-build/includes/class-scout-hub-rings.php';
require __DIR__ . '/../../scout-build/includes/class-scout-hub-approval.php';

$A    = 'Scout_Hub_Approval';
$R    = 'Scout_Hub_Rings';
$repo = Scout_Hub_Catalog::REPO;
$auto = static fn( $slug, $ver ) => $R::filter( true, (object) array( 'id' => "github.com/$repo/$slug", 'slug' => $slug, 'new_version' => $ver ) );
$GLOBALS['released']['scout-core|1.8.0'] = time() - 5 * DAY_IN_SECONDS; // Out for five days: past every ring.
$GLOBALS['released']['scout-chat|0.4.0'] = time() - 3600;             // Out for an hour.

// Live site, nothing approved.
ok( $A::on(), 'a live site waits for an OK by default' );
ok( false === $auto( 'scout-core', '1.8.0' ), 'a live site holds a release that is past its ring until it is approved' );
ok( false !== strpos( $R::wait_reason( 'scout-core', '1.8.0' ), 'Waiting for your OK' ), 'the Updates screen says it waits for an OK' );
ok( null === $R::filter( null, (object) array( 'id' => 'w.org/plugins/akismet', 'slug' => 'akismet', 'new_version' => '9' ) ), 'other plugins pass through untouched' );

// Approve.
$A::approve( 'scout-core', '1.8.0', 'Scout Core', 'Client OK by text, Nov 5' );
ok( true === $auto( 'scout-core', '1.8.0' ), 'an approved release installs' );
ok( true === $auto( 'scout-core', '1.7.9' ), 'an OK covers older versions too' );
ok( false === $auto( 'scout-core', '1.8.1' ), 'a newer version waits for its own OK' );
ok( true === $auto( 'scout-core', '1.8.0-test.5' ), 'a test build of an approved version is covered' );
$entry = end( $GLOBALS['log'] );
ok( 'approval' === $entry['kind'] && false !== strpos( $entry['title'], 'Scout Core 1.8.0' ) && 'Client OK by text, Nov 5' === $entry['detail'] && 'Scout' === $entry['source'], 'the Site log records the OK, who said yes, and is signed Scout' );
$row = $A::approvals()['scout-core'];
ok( 'Joy McNamara' === $row['by'] && '1.8.0' === $row['version'], 'the approval keeps who pressed it' );

// An approved version skips its ring.
$A::approve( 'scout-chat', '0.4.0', 'Scout Chat', '' );
ok( true === $auto( 'scout-chat', '0.4.0' ), 'an approved release installs without waiting for its ring' );

// A catalog hold and a pause still win over an OK.
$GLOBALS['catalog']['hold'] = array( array( 'slug' => 'scout-core', 'version' => '1.8.0', 'note' => 'Fix coming.' ) );
ok( false === $auto( 'scout-core', '1.8.0' ), 'a catalog hold stops an approved release' );
$GLOBALS['catalog']['hold'] = array();
update_option( $R::OPTION_PAUSE, time() + DAY_IN_SECONDS );
ok( false === $auto( 'scout-core', '1.8.0' ), 'a pause on this site stops an approved release' );
delete_option( $R::OPTION_PAUSE );

// Security fixes skip the wait.
$GLOBALS['catalog']['security'] = array( array( 'slug' => 'scout-forms-guard', 'version' => '0.3.5', 'note' => 'Upload fix' ), array( 'slug' => 'bad slug!', 'version' => 'x' ) );
$GLOBALS['released']['scout-forms-guard|0.3.5'] = time() - 600;
ok( 1 === count( $A::security() ), 'a malformed security entry is ignored' );
ok( true === $auto( 'scout-forms-guard', '0.3.5' ), 'a security fix installs at once, with no OK and no ring wait' );
ok( false === $auto( 'scout-forms-guard', '0.3.6' ), 'with the installed version unknown, only the marked release skips the wait' );
ok( false === $auto( 'scout-forms-guard', '0.3.4' ), 'a version before the security fix still waits' );
$GLOBALS['updates']->checked = array( 'scout-forms-guard/scout-forms-guard.php' => '0.3.4' );
ok( true === $auto( 'scout-forms-guard', '0.3.6' ), 'a site still below the fix takes a newer release at once, so it gets the fix' );
$GLOBALS['updates']->checked = array( 'scout-forms-guard/scout-forms-guard.php' => '0.3.5' );
ok( false === $auto( 'scout-forms-guard', '0.4.0' ), 'a site that already has the fix waits for an OK on later releases' );
$GLOBALS['updates']->checked = array();

// Staging and local copies never wait.
$GLOBALS['live'] = false;
ok( ! $A::on() && '' === $A::reason( 'scout-team', '9.0.0' ), 'staging never waits for an OK' );
update_option( $A::OPTION_MODE, 'on' );
ok( $A::on(), 'an operator can turn the hold on for one site' );
$GLOBALS['live'] = true;
update_option( $A::OPTION_MODE, 'off' );
ok( ! $A::on() && true === $auto( 'scout-team', '9.0.0' ), 'an operator can turn the hold off for one live site' );
delete_option( $A::OPTION_MODE );

// Themes.
$theme = static fn( $sheet, $ver ) => $A::filter_theme( true, (object) array( 'theme' => $sheet, 'new_version' => $ver ) );
ok( false === $theme( 'firm', '2.0.0' ), 'a theme that updates from GitHub waits for an OK' );
$A::approve( 'theme:firm', '2.0.0', 'Theme: Firm', '' );
ok( true === $theme( 'firm', '2.0.0' ), 'an approved theme version installs' );
ok( true === $theme( 'twentytwentyfive', '9.0' ), 'a theme from WordPress.org is untouched' );

// What waits, and the Inbox row.
$GLOBALS['updates']->response = array(
	'scout-hours/scout-hours.php' => (object) array( 'id' => "github.com/$repo/scout-hours", 'slug' => 'scout-hours', 'new_version' => '0.2.0' ),
	'scout-core/scout-core.php'   => (object) array( 'id' => "github.com/$repo/scout-core", 'slug' => 'scout-core', 'new_version' => '1.8.0' ),
	'akismet/akismet.php'         => (object) array( 'id' => 'w.org/plugins/akismet', 'slug' => 'akismet', 'new_version' => '9' ),
);
$GLOBALS['themes']->response = array( 'firm' => array( 'theme' => 'firm', 'new_version' => '2.1.0' ) );
$keys = array_column( $A::waiting(), 'key' );
ok( array( 'scout-hours', 'theme:firm' ) === $keys, 'waiting lists only unapproved Scout updates: ' . implode( ', ', $keys ) );
$rows = $A::inbox_row( array() );
ok( 1 === count( $rows ) && 2 === $rows[0]['count'], 'operators get one Inbox row with the count' );
$GLOBALS['operator'] = false;
ok( array() === $A::inbox_row( array() ), 'clients never see the approval row' );

echo $fail ? "\n$fail FAILED\n" : "\nAll approval hold checks passed\n";
exit( $fail ? 1 : 0 );
