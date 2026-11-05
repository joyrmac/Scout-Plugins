<?php
// Scout Law Firm Tools: "Reviewed by" due dates. Runs without WordPress:
//   php tools/tests/law-reviews.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
require __DIR__ . '/../../scout-law/includes/class-scout-law-reviews.php';
$R   = 'Scout_Law_Reviews';
$now = strtotime( '2026-11-04 12:00:00' );

ok( $R::is_due( 0, 6, $now ), 'a page never reviewed is due' );
ok( ! $R::is_due( strtotime( '2026-10-01' ), 6, $now ), 'reviewed a month ago: not due' );
ok( ! $R::is_due( strtotime( '2026-05-05' ), 6, $now ), 'reviewed just inside six months: not due' );
ok( $R::is_due( strtotime( '2026-05-03' ), 6, $now ), 'reviewed just over six months ago: due' );
ok( ! $R::is_due( strtotime( '2026-01-01' ), 12, $now ), 'with a 12 month period, ten months ago is fine' );
ok( $R::is_due( strtotime( '2026-07-01' ), 3, $now ), 'with a 3 month period, four months ago is due' );

$s = $R::settings();
ok( array( 'page' ) === $s['types'] && true === $s['show'] && 6 === $s['months'], 'defaults: pages, shown on the site, every six months' );
$GLOBALS['opts'][ $R::OPTION ] = array( 'types' => array( 'page', 'post' ), 'show' => false, 'months' => 7 );
$s = $R::settings();
ok( array( 'page', 'post' ) === $s['types'] && false === $s['show'] && 6 === $s['months'], 'saved settings read back; a period other than 3, 6 or 12 falls back to 6' );
ok( $R::stamped( 'post' ) && ! $R::stamped( 'scout_team' ), 'stamped() follows the chosen kinds' );

echo $fail ? "\n$fail FAILED\n" : "\nAll review checks passed\n";
exit( $fail ? 1 : 0 );
