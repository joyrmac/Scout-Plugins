<?php
// Scout Build runs WordPress's own Site Health and lists its items in Scout's Site health.
// Runs without WordPress: php tools/tests/wp-health.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
$GLOBALS['scheduled'] = false;
function add_action() {}
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function wp_next_scheduled( $h ) { return $GLOBALS['scheduled']; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'] = $t; }
function human_time_diff( $t ) { return '2 hours'; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
function wp_list_pluck( $l, $k ) { return array_column( $l, $k ); }
final class Scout_Hub_Watch { const HOOK = 'scout_hub_daily_watch'; }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-wp-health.php';
$W = 'Scout_Hub_WP_Health';

ok( $W::host_only( 'available_updates_disk_space', 'Disk space available to safely perform updates' ), 'disk space is the host\'s job' );
ok( $W::host_only( 'unknown_key', 'Opcode cache is not enabled' ), 'opcode cache is the host\'s job, matched by its words' );
ok( ! $W::host_only( 'plugin_version', 'You should remove inactive plugins' ), 'inactive plugins are ours to act on' );
ok( ! $W::host_only( 'scout_missing_links', 'Some of your pages link to pages that are missing' ), 'missing pages are ours to act on' );

$row = $W::row();
ok( 'Running now' === $row['value'] && false !== $GLOBALS['scheduled'], 'before the first run, the row says it is running and asks for a run' );

$GLOBALS['opts']['scout_hub_wp_health'] = array( 'at' => time() - 7200, 'items' => array(
	array( 'test' => 'plugin_version', 'label' => 'You should remove inactive plugins', 'status' => 'recommended', 'host' => false ),
	array( 'test' => 'available_updates_disk_space', 'label' => 'Disk space available to safely perform updates', 'status' => 'recommended', 'host' => true ),
	array( 'test' => 'opcode_cache', 'label' => 'Opcode cache is not enabled', 'status' => 'recommended', 'host' => true ),
	array( 'test' => 'scout_missing_links', 'label' => 'Some of your pages link to pages that are missing', 'status' => 'recommended', 'host' => false ),
) );
$row = $W::row();
ok( '2 to look at' === $row['value'] && 'warn' === $row['state'], 'only items people can act on are counted: ' . $row['value'] );
ok( false !== strpos( $row['note'], 'You should remove inactive plugins. Delete plugins that are switched off' ), 'each item is named, with plain advice where there is some' );
ok( false !== strpos( $row['note'], 'For the host, nothing to do here: Disk space available to safely perform updates; Opcode cache is not enabled.' ), 'host items are listed once, marked as nothing to do' );
ok( false !== strpos( $row['note'], 'Checked 2 hours ago.' ), 'the row says when it last ran' );

$GLOBALS['opts']['scout_hub_wp_health']['items'] = array( array( 'test' => 'opcode_cache', 'label' => 'Opcode cache is not enabled', 'status' => 'recommended', 'host' => true ) );
ok( 'All clear' === $W::row()['value'] && 'good' === $W::row()['state'], 'with only host items left, the row is all clear' );
$GLOBALS['opts']['scout_hub_wp_health']['items'][] = array( 'test' => 'https_status', 'label' => 'Your site does not use HTTPS', 'status' => 'critical', 'host' => false );
ok( 'bad' === $W::row()['state'], 'a critical item turns the row red' );

echo $fail ? "\n$fail FAILED\n" : "\nAll WordPress check checks passed\n";
exit( $fail ? 1 : 0 );
