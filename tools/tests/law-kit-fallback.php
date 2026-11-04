<?php
// Scout Law screens file under Policies, or under Search when an older admin kit (before 1.8.0,
// which has no Policies group) loaded first. Runs without WordPress:
//   php tools/tests/law-kit-fallback.php
// Each kit version needs its own PHP process, because a class can only be declared once.
if ( isset( $argv[1] ) ) {
	define( 'ABSPATH', '/' );
	function add_action() {}
	function add_filter() {}
	define( 'SCOUT_LAW_FILE', __FILE__ );
	eval( 'final class Scout_Admin_UI { const VERSION = ' . var_export( $argv[1], true ) . '; }' );
	$dir = __DIR__ . '/../../scout-law/includes/';
	foreach ( array( 'admin', 'approvals-admin', 'reviews-admin', 'entries-admin' ) as $f ) {
		require $dir . "class-scout-law-$f.php";
	}
	$out = array();
	foreach ( array( 'Scout_Law_Approvals_Admin', 'Scout_Law_Reviews_Admin', 'Scout_Law_Entries_Admin' ) as $c ) {
		$out[] = $c::nav_group( 'business', $c::SLUG ) . ',' . $c::nav_group( 'business', 'scout-team' );
	}
	echo implode( ' ', $out );
	exit;
}
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
$run = function ( $v ) { return trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $v ) ) ); };
ok( 'policies,business policies,business policies,business' === $run( '1.8.0' ), 'with admin kit 1.8.0, Approvals, Page reviews and Form entries file under Policies' );
ok( 'policies,business policies,business policies,business' === $run( '1.9.2' ), 'a newer kit keeps them under Policies' );
ok( 'search,business search,business search,business' === $run( '1.7.0' ), 'with an older kit loaded first, they file under Search, which that kit has' );
echo $fail ? "\n$fail FAILED\n" : "\nAll kit fallback checks passed\n";
exit( $fail ? 1 : 0 );
