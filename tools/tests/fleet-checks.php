<?php
// Scout Build 0.7.8's fleet checks: the Test builds, Form emails go to and
// Review totals health rows, and the hidden-from-Google alert. No WordPress needed:
//   php tools/tests/fleet-checks.php
// It loads the real classes with the WordPress functions they use stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['t'] = array();
function t_reset() {
	$GLOBALS['t'] = array(
		'home'       => 'https://example-firm.com',
		'env'        => 'production',
		'options'    => array( 'blog_public' => '1', 'admin_email' => 'owner@example-firm.com', 'blogname' => 'Example Firm' ),
		'transients' => array(),
		'site_tr'    => array(),
		'plugins'    => array(),
		'active'     => array(),
		'mail'       => array(),
		'filters'    => array(),
	);
}
t_reset();
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_url( $u ) { return $u; }
function get_option( $k, $d = false ) { return $GLOBALS['t']['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t']['options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['t']['transients'][ $k ] ?? false; }
function get_site_transient( $k ) { return $GLOBALS['t']['site_tr'][ $k ] ?? false; }
function home_url( $p = '' ) { return $GLOBALS['t']['home'] . $p; }
function admin_url( $p = '' ) { return 'https://example-firm.com/wp-admin/' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_get_environment_type() { return $GLOBALS['t']['env']; }
function apply_filters( $h, $v ) { return $GLOBALS['t']['filters'][ $h ] ?? $v; }
function get_plugins() { return $GLOBALS['t']['plugins']; }
function is_plugin_active( $f ) { return in_array( $f, $GLOBALS['t']['active'], true ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_specialchars_decode( $s, $q = 0 ) { return htmlspecialchars_decode( $s, ENT_QUOTES ); }
function wp_mail( $to, $subject, $body ) { $GLOBALS['t']['mail'][] = array( $to, $subject, $body ); return true; }
function current_user_can( $c ) { return true; }
require dirname( __DIR__, 2 ) . '/scout-build/includes/class-scout-hub-health.php';
require dirname( __DIR__, 2 ) . '/scout-build/includes/class-scout-hub-watch.php';

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}
function plugin( $slug, $version, $active = true ) {
	$file = "$slug/$slug.php";
	$GLOBALS['t']['plugins'][ $file ] = array( 'Name' => ucwords( str_replace( '-', ' ', $slug ) ), 'Version' => $version, 'Author' => 'Scout Media & Consulting', 'UpdateURI' => "https://github.com/joyrmac/Scout-Plugins/$slug" );
	if ( $active ) {
		$GLOBALS['t']['active'][] = $file;
	}
}
function feed( array $tags ) {
	$list = array();
	foreach ( $tags as $tag ) {
		$slug   = preg_replace( '/-v[0-9].*$/', '', $tag );
		$list[] = array( 'tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => array( array( 'name' => "$slug.zip", 'browser_download_url' => 'x' ) ) );
	}
	$GLOBALS['t']['transients']['scout_updater_feed'] = array( 'ok' => true, 'releases' => $list );
}

// Live or not.
foreach ( array( 'https://example-firm.com' => true, 'https://examplestage.wpengine.com' => false, 'http://localhost:8080' => false, 'https://firm.local' => false, 'http://192.168.1.20' => false, 'https://firm.test' => false ) as $home => $live ) {
	t_reset();
	$GLOBALS['t']['home'] = $home;
	ok( $live === Scout_Hub_Health::is_live(), "is_live $home: " . ( $live ? 'live' : 'not live' ) );
}

// Test builds.
t_reset();
plugin( 'scout-build', '0.7.8' );
plugin( 'scout-forms-guard', '0.2.0' );
feed( array( 'scout-build-v0.7.8', 'scout-build-v0.7.7', 'scout-forms-guard-v0.2.0' ) );
$r = Scout_Hub_Health::test_builds();
ok( 'good' === $r['state'] && 'None' === $r['value'], 'released versions: good, None' );

t_reset();
plugin( 'scout-build', '0.6.7-test.1' );
feed( array( 'scout-build-v0.7.8' ) );
$r = Scout_Hub_Health::test_builds();
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Scout Build 0.6.7-test.1 is a test build' ) && false !== strpos( $r['note'], 'once 0.6.7 is released' ), 'a -test version: warn, names it and the release it waits for' );

t_reset();
plugin( 'scout-forms-guard', '0.3.0' );
plugin( 'scout-build', '0.6.7' );
feed( array( 'scout-forms-guard-v0.2.0', 'scout-build-v0.6.6' ) );
$r = Scout_Hub_Health::test_builds();
ok( 'warn' === $r['state'] && '2 plugins' === $r['value'] && false !== strpos( $r['note'], 'Scout Forms Guard 0.3.0 is newer than the newest release (0.2.0)' ), 'newer than any release: warn, 2 plugins' );

t_reset();
plugin( 'scout-forms-guard', '0.3.0' );
feed( array( 'scout-build-v0.7.8' ) );
$r = Scout_Hub_Health::test_builds();
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'has no release yet' ), 'no release at all: warn, has no release yet' );

t_reset();
plugin( 'scout-forms-guard', '0.3.0' );
$GLOBALS['t']['transients']['scout_updater_scout-forms-guard'] = array( 'version' => '0.3.0', 'package' => 'x', 'notes' => '' );
feed( array() );
$r = Scout_Hub_Health::test_builds();
ok( 'good' === $r['state'], "the updater's own per-plugin answer wins over the feed" );

t_reset();
plugin( 'scout-forms-guard', '0.3.0' );
$GLOBALS['t']['site_tr']['update_plugins'] = (object) array( 'no_update' => array( 'scout-forms-guard/scout-forms-guard.php' => (object) array( 'id' => 'github.com/joyrmac/Scout-Plugins/scout-forms-guard', 'new_version' => '0.2.0' ) ), 'response' => array() );
$r = Scout_Hub_Health::test_builds();
ok( 'warn' === $r['state'], "falls back to WordPress's update check when the updater cache is empty" );

t_reset();
plugin( 'scout-forms-guard', '0.3.0' );
$r = Scout_Hub_Health::test_builds();
ok( 'good' === $r['state'], 'nothing cached: no guess, good' );

t_reset();
plugin( 'scout-build', '0.6.7-test.1', false );
$r = Scout_Hub_Health::test_builds();
ok( 'good' === $r['state'], 'inactive plugins are skipped' );

// Form emails go to.
class GFAPI {
	public static $forms = array();
	public static function get_forms() { return self::$forms; }
}
function forms_row( array $forms, $home = 'https://example-firm.com' ) {
	t_reset();
	$GLOBALS['t']['home'] = $home;
	GFAPI::$forms         = $forms;
	return Scout_Hub_Health::form_emails();
}
$r = forms_row(
	array(
		array( 'id' => 1, 'title' => 'Request service', 'notifications' => array( array( 'isActive' => true, 'toType' => 'email', 'to' => '{admin_email}, andy@example-firm.com' ), array( 'isActive' => true, 'toType' => 'field', 'to' => '3' ) ) ),
		array( 'id' => 2, 'title' => 'Quote', 'notifications' => array( array( 'toType' => 'routing', 'to' => '' ), array( 'isActive' => true, 'toType' => 'email', 'to' => '{Email:4}' ) ) ),
	)
);
ok( 'good' === $r['state'] && false !== strpos( $r['note'], 'Request service (form 1): owner@example-firm.com, andy@example-firm.com, the address in field 3.' ) && false !== strpos( $r['note'], 'Quote (form 2): routing rules, the address in field 4.' ), 'resolves {admin_email}, field and routing recipients' );
$r = forms_row( array( array( 'id' => 1, 'title' => 'Contact', 'notifications' => array( array( 'isActive' => false, 'toType' => 'email', 'to' => 'a@b.com' ) ) ) ) );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Contact (form 1): no email is sent.' ), 'live site, form with only an inactive notification: warn' );
$r = forms_row( array( array( 'id' => 1, 'title' => 'Contact', 'notifications' => array( array( 'toType' => 'email', 'to' => 'joy@scoutraleigh.com' ) ) ) ) );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'emails only Scout' ), 'live site, form emailing only scoutraleigh.com: warn' );
$r = forms_row( array( array( 'id' => 1, 'title' => 'Contact', 'notifications' => array( array( 'toType' => 'email', 'to' => 'joy@scoutraleigh.com, andy@example-firm.com' ) ) ) ) );
ok( 'good' === $r['state'], 'Scout plus the business: good' );
$r = forms_row( array( array( 'id' => 1, 'title' => 'Contact', 'notifications' => array( array( 'toType' => 'email', 'to' => 'joy@scoutraleigh.com' ) ) ) ), 'https://examplestage.wpengine.com' );
ok( 'good' === $r['state'], 'staging copy emailing only Scout: good' );

// Review totals.
class Scout_Reviews_Settings {
	public static $profiles = array();
	public static function get() { return array( 'profiles' => self::$profiles ); }
}
t_reset();
ok( null === Scout_Hub_Health::review_totals(), 'no totals entered: no row' );
Scout_Reviews_Settings::$profiles = array( 'google' => array( 'rating' => 5.0, 'count' => 17 ) );
$r = Scout_Hub_Health::review_totals();
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Update the totals once to start tracking.' ), 'no timestamp yet: warn, update once' );
update_option( 'scout_reviews_totals_updated', time() - 5 * DAY_IN_SECONDS );
$r = Scout_Hub_Health::review_totals();
ok( 'good' === $r['state'] && '5 days ago' === $r['value'], 'updated 5 days ago: good' );
update_option( 'scout_reviews_totals_updated', time() - 31 * DAY_IN_SECONDS );
$r = Scout_Hub_Health::review_totals();
ok( 'warn' === $r['state'] && '31 days ago' === $r['value'], 'updated 31 days ago: warn' );

// The hidden-from-Google alert.
t_reset();
ok( false === Scout_Hub_Watch::check() && ! $GLOBALS['t']['mail'], 'live and visible: no email' );
$GLOBALS['t']['options']['blog_public'] = '0';
ok( true === Scout_Hub_Watch::check(), 'live and hidden: email sent' );
$m = $GLOBALS['t']['mail'][0] ?? array( '', '', '' );
ok( 'team@scoutraleigh.com' === $m[0] && 'Example Firm is hidden from Google' === $m[1], 'to team@, subject names the site' );
ok( false === Scout_Hub_Watch::check() && 1 === count( $GLOBALS['t']['mail'] ), 'a second check the same day sends nothing' );
$GLOBALS['t']['options']['scout_hub_hidden_alert_sent'] = time() - 6 * DAY_IN_SECONDS;
ok( false === Scout_Hub_Watch::check(), 'six days later: still quiet' );
$GLOBALS['t']['options']['scout_hub_hidden_alert_sent'] = time() - 7 * DAY_IN_SECONDS - 60;
ok( true === Scout_Hub_Watch::check() && 2 === count( $GLOBALS['t']['mail'] ), 'a week later and still hidden: sent again' );
$GLOBALS['t']['options']['blog_public'] = '1';
Scout_Hub_Watch::check();
ok( false === get_option( 'scout_hub_hidden_alert_sent' ), 'visible again: the weekly clock resets' );
$GLOBALS['t']['options']['blog_public'] = '0';
ok( true === Scout_Hub_Watch::check(), 'hidden again: email goes out straight away' );
t_reset();
$GLOBALS['t']['options']['blog_public'] = '0';
$GLOBALS['t']['home']                   = 'https://examplestage.wpengine.com';
ok( false === Scout_Hub_Watch::check() && ! $GLOBALS['t']['mail'], 'staging and hidden: no email' );
t_reset();
$GLOBALS['t']['options']['blog_public']           = '0';
$GLOBALS['t']['filters']['scout_build_alert_email'] = 'ops@partner.example';
Scout_Hub_Watch::check();
ok( 'ops@partner.example' === ( $GLOBALS['t']['mail'][0][0] ?? '' ), 'scout_build_alert_email filter changes the address' );
t_reset();
$GLOBALS['t']['options']['blog_public'] = '0';
ob_start();
Scout_Hub_Watch::notice();
ok( false !== strpos( ob_get_clean(), 'This live site is hidden from Google.' ), 'admin notice on a hidden live site' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
