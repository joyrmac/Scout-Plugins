<?php
// Scout Build's "Search engines" health row on live and staging sites. No WordPress needed:
//   php tools/tests/staging-check.php
// It loads the real health class with the few WordPress functions the row uses stubbed out.
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_home'] = '';
$GLOBALS['t_env']  = 'production';
$GLOBALS['t_pub']  = '1';
function __( $s, $d = '' ) { return $s; }
function get_option( $k, $d = false ) { return 'blog_public' === $k ? $GLOBALS['t_pub'] : $d; }
function home_url( $p = '' ) { return $GLOBALS['t_home'] . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_get_environment_type() { return $GLOBALS['t_env']; }
function apply_filters( $h, $v ) { return $v; }
function get_transient( $k ) { return 'no'; } // The 0.7.7 password probe: not behind a password.
require dirname( __DIR__, 2 ) . '/scout-build/includes/class-scout-hub-health.php';

$fail = 0;
function row( $home, $env, $pub ) {
	$GLOBALS['t_home'] = $home;
	$GLOBALS['t_env']  = $env;
	$GLOBALS['t_pub']  = $pub;
	return Scout_Hub_Health::search_engines();
}
function ok( $cond, $msg ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$r = row( 'https://example-firm.com', 'production', '1' );
ok( 'good' === $r['state'] && 'Allowed' === $r['value'], 'live site, search engines allowed: good, Allowed' );
$r = row( 'https://example-firm.com', 'production', '0' );
ok( 'warn' === $r['state'] && 'Hidden' === $r['value'], 'live site, search engines hidden: warn, Hidden (unchanged)' );
$r = row( 'https://examplestage.wpenginepowered.com', 'production', '1' );
ok( 'warn' === $r['state'] && 'Allowed on staging' === $r['value'], 'WP Engine staging address, allowed: warn, Allowed on staging' );
$r = row( 'https://EXAMPLE.Kinsta.Cloud', 'production', '1' );
ok( 'warn' === $r['state'], 'staging suffix matches in any letter case' );
$r = row( 'https://example-firm.com', 'staging', '1' );
ok( 'warn' === $r['state'] && 'Allowed on staging' === $r['value'], 'environment type staging, allowed: warn' );
$r = row( 'https://example-firm.com', 'local', '1' );
ok( 'warn' === $r['state'], 'environment type local, allowed: warn' );
$r = row( 'https://examplestage.wpenginepowered.com', 'production', '0' );
ok( 'warn' === $r['state'] && 'Hidden' === $r['value'], 'staging address, hidden: reads as it always has' );
$r = row( 'https://wpenginepowered.com.example-firm.com', 'production', '1' );
ok( 'good' === $r['state'], 'a live domain that only contains a staging suffix stays good' );

echo $fail ? "$fail failed\n" : "All passed\n";
exit( $fail ? 1 : 0 );
