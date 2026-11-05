<?php
// Scout Build: plans, picks and recommendations. Runs without WordPress:
//   php tools/tests/plans.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array(); $GLOBALS['use'] = array();
function __( $s ) { return $s; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
class Scout_Hub_Setup { static function feature_plan() { return array( 'use' => $GLOBALS['use'], 'skip' => array() ); } }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-access.php';
$A = 'Scout_Hub_Access';
$plan = function ( $p ) { $GLOBALS['opts'][ Scout_Hub_Access::PLAN_OPTION ] = $p; };

$plan( '' );
ok( ! $A::included( 'build' ), 'with no plan set, nothing reads as included' );
$plan( 'build' );
ok( $A::included( 'build' ) && ! $A::included( 'partnership' ), 'The Build includes Core only' );
ok( ! $A::included( 'bonus' ) && 'Bonus from Scout' === $A::tier_label( 'bonus' ), 'a Bonus on The Build reads Bonus from Scout' );
$plan( 'partnership' );
ok( $A::included( 'partnership' ) && ! $A::included( 'whole' ), 'The Partnership includes its own features and Core' );
$plan( 'whole' );
ok( $A::included( 'bonus' ) && $A::included( 'addon' ), 'The Whole System includes every Bonus (and the old addon tier)' );
ok( ! $A::included( 'plus' ) && 'Plus: added on its own' === $A::tier_label( 'plus' ), 'The Whole System does not include Plus modules' );
$A::pick( 'scout-events', true );
ok( $A::included( 'plus', 'scout-events' ) && 'Picked for this site' === $A::tier_label( 'plus', 'scout-events' ), 'a Plus module added to the plan reads Picked for this site' );
$A::pick( 'scout-events', false );
ok( ! $A::included( 'plus', 'scout-events' ), 'taking it out of the plan works' );

$plan( 'ff' );
ok( 'Friends and family' === $A::PLANS['ff'], 'Friends and family is a plan' );
ok( $A::included( 'build' ) && ! $A::included( 'partnership' ) && ! $A::included( 'bonus' ), 'Friends and family starts with Core only' );
$GLOBALS['use'] = array( 'scout-chat' );
ok( $A::included( 'bonus', 'scout-chat' ), "on Friends and family, the theme's setup file picks count" );
$plan( 'build' );
ok( ! $A::included( 'bonus', 'scout-chat' ), 'the setup file only picks for Friends and family' );

ok( array( 'scout-chat' ) === $A::recommended(), 'the setup file list is recommended' );
$A::recommend( 'scout-reviews', true );
ok( in_array( 'scout-reviews', $A::recommended(), true ), 'an operator can recommend a feature' );
$A::recommend( 'scout-reviews', false );
ok( ! in_array( 'scout-reviews', $A::recommended(), true ), 'and stop recommending it' );

$plan( 'bogus' );
ok( '' === $A::plan(), 'an unknown plan reads as not set' );

$cat = json_decode( file_get_contents( __DIR__ . '/../../catalog.json' ), true );
$tiers = array_column( $cat['features'], 'tier', 'slug' );
ok( 'bonus' === $tiers['scout-chat'] && 'plus' === $tiers['scout-events'] && 'build' === $tiers['scout-core'], 'the catalog sorts features into Core, Bonus and Plus' );
ok( ! in_array( 'addon', $tiers, true ), 'no feature uses the old addon tier' );

echo $fail ? "\n$fail FAILED\n" : "\nAll plan checks passed\n";
exit( $fail ? 1 : 0 );
