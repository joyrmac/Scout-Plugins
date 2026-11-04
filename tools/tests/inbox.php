<?php
// Scout Build: the Inbox list. Runs without WordPress:
//   php tools/tests/inbox.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['meta'] = array(); $GLOBALS['um'] = array(); $GLOBALS['extra'] = array(); $GLOBALS['rows'] = array();
class WP_Post { public $ID; public $post_title; public $post_content = ''; public $t; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function add_action() {}
function apply_filters( $tag, $v ) { return 'scout_hub_inbox_items' === $tag ? array_merge( $v, $GLOBALS['extra'] ) : $v; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function wp_trim_words( $t ) { return $t; }
function wp_strip_all_tags( $t ) { return strip_tags( $t ); }
function get_post_meta( $id, $k ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_post_time( $f, $g, $p ) { return $p->t; }
function get_the_title( $p ) { return $p->post_title; }
function post_type_exists() { return false; }
function get_user_meta( $u, $k ) { return $GLOBALS['um'][ $k ] ?? ''; }
class Scout_Hub_Requests { const META_STATUS = 's'; const STATUSES = array( 'received' => 'Received', 'progress' => 'In progress', 'done' => 'Done' ); static function all() { return $GLOBALS['reqs']; } }
class Scout_Hub_Admin { static function url( $t, $a = array() ) { return '/hub/' . $t; } static function waiting_on_you() { return $GLOBALS['rows']; } }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-inbox.php';
$I = 'Scout_Hub_Inbox';
$now = time();
$mk = function ( $id, $title, $t, $status ) { $p = new WP_Post(); $p->ID = $id; $p->post_title = $title; $p->t = $t; $GLOBALS['meta'][ $id ]['s'] = $status; return $p; };
$GLOBALS['reqs'] = array( $mk( 1, 'Swap the Home photo', $now - 3600, 'progress' ), $mk( 2, 'New team photo', $now - 3 * DAY_IN_SECONDS, 'done' ), $mk( 3, 'Old request', $now - 30 * DAY_IN_SECONDS, 'done' ) );
$GLOBALS['extra'] = array( array( 'id' => 'law-1', 'kind' => 'law', 'label' => 'Waiting for approval', 'from' => 'Car accidents', 'when' => $now - 60, 'url' => '/law' ), array( 'label' => 'no id or url' ) );
$GLOBALS['rows'] = array( array( 'label' => 'Change requests', 'count' => 1, 'url' => '/r', 'note' => '' ), array( 'label' => 'Disclaimers to approve', 'count' => 2, 'url' => '/d', 'note' => 'Wording the firm has not approved.' ) );
$items = $I::items();
$ids = array_column( $items, 'id' );
ok( ! in_array( 'request-3', $ids, true ), 'done requests older than two weeks leave the list' );
ok( in_array( 'request-2', $ids, true ), 'a recently finished request stays so the client sees it done' );
ok( 'Your request · In progress' === $items[ array_search( 'request-1', $ids, true ) ]['label'], 'a request shows its status' );
ok( in_array( 'law-1', $ids, true ) && ! in_array( '', $ids, true ), 'plugins add items; broken items are dropped' );
ok( 'other' === $items[ array_search( 'law-1', $ids, true ) ]['kind'], 'an unknown kind files under Other' );
ok( in_array( 'queue-disclaimers-to-approve', $ids, true ), 'a count row from another plugin shows as one item' );
ok( ! in_array( 'queue-change-requests', $ids, true ), 'built-in queues are not shown twice' );
$whens = array_column( $items, 'when' ); $sorted = $whens; rsort( $sorted );
ok( $whens === $sorted, 'newest first' );
$w = $I::waiting( $items );
ok( ! in_array( 'request-2', array_column( $w, 'id' ), true ) && count( $w ) === count( $items ) - 1, 'a finished request never counts as waiting' );
// The bubble, the All chip and Today count from the same list (a site check request once showed in the list but not the bubble).
$GLOBALS['extra'][] = array( 'id' => 'sitecheck-9', 'kind' => 'sitecheck', 'label' => 'Site check request', 'from' => 'a@b.co', 'when' => $now - 21 * DAY_IN_SECONDS, 'url' => '/sc' );
$items = $I::items();
$w     = $I::waiting( $items );
ok( 5 === $I::total( $items ), 'the total counts every waiting item, and a count row adds its own number (1 request + 1 law + 2 disclaimers + 1 site check)' );
ok( count( $w ) === 4 && in_array( 'sitecheck-9', array_column( $w, 'id' ), true ), 'a site check request is in the list and in the count' );
$rows = $I::rows( $items );
$byl  = array_column( $rows, 'count', 'label' );
ok( 1 === ( $byl['Site check requests'] ?? 0 ) && 1 === ( $byl['Change requests'] ?? 0 ), 'Today gets one row per kind, with its count' );
ok( 2 === ( $byl['Disclaimers to approve'] ?? 0 ) && 1 === ( $byl['Waiting for approval'] ?? 0 ), 'Other items stay their own Today rows' );
ok( array_sum( array_column( $rows, 'count' ) ) === $I::total( $items ), 'Today rows add up to the bubble' );
ok( 'never' === $I::pref( 1 ), 'nobody gets Inbox emails until they choose' );
$GLOBALS['um'][ Scout_Hub_Inbox::PREF ] = 'daily';
ok( 'daily' === $I::pref( 1 ), 'a person can choose once a day' );
$GLOBALS['um'][ Scout_Hub_Inbox::PREF ] = 'hourly-spam';
ok( 'never' === $I::pref( 1 ), 'an unknown setting falls back to never' );
echo $fail ? "\n$fail FAILED\n" : "\nAll Inbox checks passed\n";
exit( $fail ? 1 : 0 );
