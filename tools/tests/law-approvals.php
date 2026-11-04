<?php
// Scout Law Firm Tools: approval before publishing, the status rules. Runs without WordPress:
//   php tools/tests/law-approvals.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
$GLOBALS['meta'] = array();
class WP_Post { public $ID; public $post_title; public $post_content; public $post_status; public $post_type = 'post'; }
function __( $s ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_get_post_revisions() { return array( 77 => true ); }
function get_current_user_id() { return 3; }
require __DIR__ . '/../../scout-law/includes/class-scout-law-approvals.php';
$A = 'Scout_Law_Approvals';

ok( array( 'post', 'scout_team' ) === $A::types(), 'blog posts and team bios need approval to start' );
update_option( $A::OPTION, array( 'types' => array( 'page' ) ) );
ok( $A::gated( 'page' ) && ! $A::gated( 'post' ), 'the firm can choose what needs approval' );
update_option( $A::OPTION, array( 'types' => array() ) );
ok( array() === $A::types(), 'the firm can turn approval off for everything' );

$p = new WP_Post(); $p->ID = 9; $p->post_title = 'Car accidents'; $p->post_content = 'We help people after a crash.'; $p->post_status = 'pending';
$GLOBALS['posts'][9] = $p;
ok( 'waiting' === $A::status( $p ), 'a new page waits on the firm' );
$A::record( 9, '  ' );
ok( 'waiting' === $A::status( $p ), 'an approval needs a name' );
$A::record( 9, 'Dana Whitfield' );
ok( 'approved' === $A::status( $p ), 'a recorded approval reads Approved' );
ok( 77 === $A::log()[0]['revision'] && 'Dana Whitfield' === $A::log()[0]['by'], 'the record keeps the attorney and the exact version' );
$p->post_content = "We help people   after a crash.\n";
ok( 'approved' === $A::status( $p ), 'extra spaces do not count as an edit' );
$p->post_content = 'We win every case.';
ok( 'edited' === $A::status( $p ), 'changed words read Edited since approval' );
$p->post_content = 'We help people after a crash.'; $p->post_title = 'Car crashes';
ok( 'edited' === $A::status( $p ), 'a changed title counts as an edit too' );

echo $fail ? "\n$fail FAILED\n" : "\nAll approval checks passed\n";
exit( $fail ? 1 : 0 );
