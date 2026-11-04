<?php
// Scout Core 1.7.0: breadcrumbs, verification codes, thank-you pages and hardening. Runs without WordPress:
//   php tools/tests/core-seo-gaps.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['opts']      = array( 'page_on_front' => 12, 'page_for_posts' => 0 );
$GLOBALS['transient'] = false;
$GLOBALS['logged_in'] = false;
$GLOBALS['ssl']       = true;
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function remove_action() {}
function __( $s ) { return $s; }
function esc_attr__( $s ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_transient( $k ) { return $GLOBALS['transient']; }
function set_transient( $k, $v ) { $GLOBALS['transient'] = $v; }
function get_posts( $a ) { return array( 40 ); } // A page with the address thank-you.
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function is_ssl() { return $GLOBALS['ssl']; }
function home_url( $p = '' ) { return 'https://firm.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function url_to_postid( $u ) { return array( 'https://firm.test/message-sent/' => 41, 'https://firm.test/' => 12 )[ $u ] ?? 0; }
final class GFAPI {
	public static function get_forms() {
		return array(
			array( 'id' => 1, 'confirmations' => array(
				array( 'isActive' => true, 'type' => 'redirect', 'url' => 'https://firm.test/message-sent/' ),
				array( 'type' => 'page', 'pageId' => 12 ),                                   // Sends people to the home page.
				array( 'isActive' => true, 'type' => 'redirect', 'url' => 'https://other.test/thanks/' ), // Another site.
				array( 'isActive' => false, 'type' => 'page', 'pageId' => 77 ),              // Switched off.
				array( 'isActive' => true, 'type' => 'message', 'message' => 'Thanks!' ),
			) ),
		);
	}
}
require __DIR__ . '/../../scout-core/includes/seo/seo-settings.php';
require __DIR__ . '/../../scout-core/includes/seo/class-seo-head.php';
require __DIR__ . '/../../scout-core/includes/seo/class-seo-breadcrumbs.php';
require __DIR__ . '/../../scout-core/includes/protection.php';
require __DIR__ . '/../../scout-core/includes/cache-purge.php';

// Verification codes.
$S = 'Scout_Core_SEO_Settings';
ok( 'AbC_123-xyz=' === $S::code( '<meta name="google-site-verification" content="AbC_123-xyz=" />' ), 'a pasted Google tag keeps only its code' );
ok( '1A2B3C' === $S::code( "<meta name='msvalidate.01' content='1A2B3C'>" ), 'a pasted Bing tag with single quotes keeps only its code' );
ok( 'abc123' === $S::code( '  abc123  ' ), 'a bare code is trimmed' );
ok( '' === $S::code( 'google8245b9fe93916e79.html' ), 'a Google HTML file name is refused: it is a file check, not a code' );
ok( 'abc' === $S::code( 'a<b>c"' ) && 120 === strlen( $S::code( str_repeat( 'a', 300 ) ) ), 'stray characters are dropped and a code is capped at 120' );

// Thank-you pages.
$H   = 'Scout_SEO_Head';
$ids = $H::thanks_pages();
sort( $ids );
ok( array( 40, 41 ) === $ids, 'thank-you pages: the thank-you address and the Gravity Forms redirect on this site' );
ok( ! in_array( 12, $ids, true ), 'a form that sends people to the home page never hides the home page' );
ok( ! in_array( 77, $ids, true ), 'a switched-off confirmation is ignored' );
ok( 0 === $H::confirmation_page( array( 'type' => 'redirect', 'url' => 'https://other.test/thanks/' ) ), 'a redirect to another site is ignored' );

// Breadcrumbs.
$B     = 'Scout_SEO_Breadcrumbs';
$trail = $B::clean( array( array( 'name' => 'Home', 'url' => 'https://firm.test/' ), array( 'name' => '', 'url' => 'https://firm.test/x/' ), array( 'name' => 'Services', 'url' => 'https://firm.test/services/' ), array( 'name' => 'Services again', 'url' => 'https://firm.test/services/' ), array( 'name' => '<b>Car accidents</b>', 'url' => 'https://firm.test/services/car/' ) ) );
ok( 3 === count( $trail ) && 'Car accidents' === $trail[2]['name'], 'the trail drops empty names, repeated addresses and tags' );
ok( array() === $B::clean( array( array( 'name' => 'Home', 'url' => 'https://firm.test/' ) ) ), 'a trail with only Home is no trail' );
$node = $B::node( $trail );
ok( 'BreadcrumbList' === $node['@type'] && 3 === $node['itemListElement'][2]['position'] && 'https://firm.test/services/car/#breadcrumb' === $node['@id'], 'the schema node numbers each step and is named after the page' );
$html = $B::html( $trail );
ok( false !== strpos( $html, 'aria-label="Breadcrumb"' ) && false !== strpos( $html, '<span aria-current="page">Car accidents</span>' ) && 2 === substr_count( $html, '<a ' ), 'the visible links: a labelled nav, the current page not linked' );

// Hardening.
$P = 'Scout_Core_Protection';
$routes = array( '/wp/v2/users' => 1, '/wp/v2/users/(?P<id>[\d]+)' => 1, '/wp/v2/users/me' => 1, '/wp/v2/posts' => 1 );
ok( array( '/wp/v2/posts' ) === array_keys( $P::hide_user_routes( $routes ) ), 'visitors cannot reach any users route' );
$GLOBALS['logged_in'] = true;
ok( 4 === count( $P::hide_user_routes( $routes ) ), 'signed-in editors keep the users routes (the block editor needs them)' );
$h = $P::browser_headers( array( 'X-Frame-Options' => 'DENY' ) );
ok( 'DENY' === $h['X-Frame-Options'] && 'nosniff' === $h['X-Content-Type-Options'] && isset( $h['Strict-Transport-Security'] ), 'browser protections are added, and a header the host already set is kept' );
$GLOBALS['ssl'] = false;
ok( ! isset( $P::browser_headers( array() )['Strict-Transport-Security'] ), 'no secure-connection header on a site without SSL' );
ok( ! $P::xmlrpc_needed(), 'XML-RPC is not needed without Jetpack' );
$f = $P::fields();
ok( isset( $f['hide_users'], $f['xmlrpc_off'], $f['browser_headers'] ), 'the three hardening switches are listed' );
$GLOBALS['opts']['scout_core_protection'] = array( 'email_guard' => '1', 'close_comments' => '0' );
ok( $P::on( 'hide_users' ) && $P::on( 'xmlrpc_off' ) && ! $P::on( 'close_comments' ), 'a site that saved the old switches gets the new ones on, and keeps its own choices' );

// Cache purge after an update.
$C = 'Scout_Core_Cache';
ok( 'theme' === $C::reason( array( 'type' => 'theme', 'action' => 'update', 'themes' => array( 'scout-media' ) ), '', 'scout-media', 'scout-media' ), 'the active theme updating purges' );
ok( 'theme' === $C::reason( array( 'type' => 'theme', 'action' => 'install' ), 'scout-base', 'firm-child', 'scout-base' ), 'replacing the parent theme by upload purges' );
ok( '' === $C::reason( array( 'type' => 'theme', 'action' => 'update', 'themes' => array( 'twentytwentyfive' ) ), '', 'scout-media', 'scout-media' ), 'an inactive theme updating does not purge' );
ok( 'plugin' === $C::reason( array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'akismet/akismet.php', 'scout-core/scout-core.php' ) ), '', 'x', 'x' ), 'a bulk update that includes a Scout plugin purges once' );
ok( '' === $C::reason( array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'akismet/akismet.php' ) ), '', 'x', 'x' ), 'another plugin updating does not purge' );
ok( 'plugin' === $C::reason( array( 'type' => 'plugin', 'action' => 'install' ), 'scout-law', 'x', 'x' ), 'uploading a Scout plugin zip purges' );
ok( '' === $C::reason( array( 'type' => 'translation' ), '', 'x', 'x' ), 'a translation update does not purge' );

echo $fail ? "\n$fail FAILED\n" : "\nAll Core 1.7.0 checks passed\n";
exit( $fail ? 1 : 0 );
