<?php
// Scout Build: the Today list on Home. Runs without WordPress:
//   php tools/tests/today.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['um'] = array(); $GLOBALS['rows'] = array(); $GLOBALS['checks'] = array(); $GLOBALS['extra'] = array(); $GLOBALS['caps'] = array( 'edit_posts' => true, 'manage_options' => true ); $GLOBALS['now'] = '20261104';
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function apply_filters( $tag, $v ) { return 'scout_hub_today' === $tag ? array_merge( $v, $GLOBALS['extra'] ) : $v; }
function add_action() {}
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function current_user_can( $c ) { return ! empty( $GLOBALS['caps'][ $c ] ); }
function wp_date( $f, $t = null ) { return null === $t ? $GLOBALS['now'] : date( 'Ymd', strtotime( $GLOBALS['now'] ) + DAY_IN_SECONDS ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function get_user_meta( $u, $k, $s ) { return $GLOBALS['um'][ $k ] ?? ''; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['um'][ $k ] = $v; }
class Scout_Hub_Admin { static function waiting_on_you() { return $GLOBALS['rows']; } static function url( $t ) { return '/hub/' . $t; } }
class Scout_Hub_Health { static function checks() { return $GLOBALS['checks']; } }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-today.php';
$T = 'Scout_Hub_Today';

ok( array() === $T::for_user( 1 )['items'], 'nothing waiting means an empty Today (the all caught up screen)' );

$GLOBALS['rows']   = array( array( 'label' => 'Chat questions', 'count' => 2, 'url' => '/chat', 'note' => 'Questions visitors asked.' ), array( 'label' => 'New reviews', 'count' => 0, 'url' => '/r', 'note' => '' ) );
$GLOBALS['checks'] = array( array( 'label' => 'Secure connection', 'state' => 'bad', 'note' => 'No SSL.' ), array( 'label' => 'PHP', 'state' => 'warn', 'note' => '' ) );
$GLOBALS['extra']  = array( array( 'id' => 'law-approvals', 'label' => 'Two practice pages wait for approval', 'url' => '/law', 'minutes' => 10, 'priority' => 30, 'cap' => 'manage_options' ), array( 'label' => 'No id, dropped' ) );
$items = $T::for_user( 1 )['items'];
ok( 3 === count( $items ), 'Inbox rows with a count, failing checks and plugin items make the list; empty rows and broken items do not' );
ok( 'health-secure-connection' === $items[0]['id'], 'a failing health check comes before Inbox items' );
ok( 'Chat questions (2)' === $items[1]['label'], 'Inbox items read naturally, with the count' );
ok( 6 === $items[1]['minutes'], 'time grows with how many are waiting' );
ok( 'law-approvals' === $items[2]['id'], 'a plugin can add its own Today item' );

$GLOBALS['caps']['manage_options'] = false;
ok( 1 === count( $T::for_user( 2 )['items'] ), 'staff do not see items that need site settings' );
$GLOBALS['caps']['manage_options'] = true;

$GLOBALS['um'][ Scout_Hub_Today::SNOOZE ] = array( 'law-approvals' => '20261105' );
$r = $T::for_user( 1 );
ok( 2 === count( $r['items'] ) && 1 === $r['snoozed'], '"Not today" hides an item until tomorrow' );
$GLOBALS['now'] = '20261105';
$GLOBALS['um'][ Scout_Hub_Today::SEEN ] = array();
ok( 3 === count( $T::for_user( 1 )['items'] ), 'the item comes back the next day' );

$GLOBALS['rows'][0]['count'] = 0;
$r = $T::for_user( 1 );
ok( 2 === count( $r['items'] ) && isset( $r['done']['inbox-chat-questions'] ), 'an item that leaves the list counts as done earlier today' );
$GLOBALS['now'] = '20261106';
ok( array() === $T::for_user( 1 )['done'], 'done resets each day' );

echo $fail ? "\n$fail FAILED\n" : "\nAll Today checks passed\n";
exit( $fail ? 1 : 0 );
