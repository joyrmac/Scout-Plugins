<?php
// The security baseline in Site status (Site Standard rule 22): each row's ok and look logic.
// Runs without WordPress: php tools/tests/security-baseline.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['opts']      = array();
$GLOBALS['trans']     = array();
$GLOBALS['scheduled'] = false;
$GLOBALS['spawned']   = false;
$GLOBALS['can']       = true;
function add_action() {}
function apply_filters( $hook, $value ) { return $value; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_site_option( $k, $d = false ) { return $d; }
function is_multisite() { return false; }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return $GLOBALS['scheduled']; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['scheduled'] = $t; }
function spawn_cron() { $GLOBALS['spawned'] = true; }
function current_user_can( $cap ) { return $GLOBALS['can']; }
function human_time_diff( $from, $to = 0 ) { return '2 hours'; }
function admin_url( $p ) { return '/wp-admin/' . $p; }
final class Scout_Hub_Watch { const HOOK = 'scout_hub_daily_watch'; }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-security.php';
$S       = 'Scout_Hub_Security';
$now     = strtotime( '2026-11-05 12:00:00 UTC' );
$protect = '/wp-admin/admin.php?page=scout&tab=protect';
$day     = DAY_IN_SECONDS;

// XML-RPC.
$r = $S::xmlrpc_row( false, false, true, $protect );
ok( 'good' === $r['state'] && 'Off' === $r['value'], 'XML-RPC off is ok' );
$r = $S::xmlrpc_row( true, true, true, $protect );
ok( 'good' === $r['state'] && 'On for Jetpack' === $r['value'], 'XML-RPC on for Jetpack is ok, as Spam protection already allows' );
$r = $S::xmlrpc_row( true, false, true, $protect );
ok( 'warn' === $r['state'] && $protect === ( $r['link'] ?? '' ), 'XML-RPC on without Jetpack wants a look and points to Spam protection' );
$r = $S::xmlrpc_row( true, false, false, $protect );
ok( 'warn' === $r['state'] && ! isset( $r['link'] ) && false !== strpos( $r['note'], 'Scout Core' ), 'without Scout Core, the note says to turn it on' );

// File editing.
ok( 'good' === $S::file_edit_row( true )['state'], 'file editing off is ok' );
$r = $S::file_edit_row( false );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], "DISALLOW_FILE_EDIT" ) && false !== strpos( $r['note'], 'wp-config.php' ), 'file editing on wants a look and says how to turn it off' );

// Admins.
$admin = static fn( $name, $login, $last, $reg = 0 ) => array( 'name' => $name, 'login' => $login, 'last' => $last, 'registered' => $reg ?: $now - 400 * $day, 'two_step' => null );
$r = $S::admins_row( array( $admin( 'Joy McNamara', 'joy', $now - 3 * $day ), $admin( 'Sean McNamara', 'sean', $now - 10 * $day ) ), $now, $now - 400 * $day );
ok( 'good' === $r['state'] && '2 admins' === $r['value'] && false !== strpos( $r['note'], 'Joy McNamara, Sean McNamara' ), 'named admins who sign in are ok, listed by display name' );
$r = $S::admins_row( array( $admin( 'Joy McNamara', 'joy', $now - 3 * $day ), $admin( 'Admin', 'admin', $now - $day ) ), $now, $now );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Admin (a generic name)' ), 'a generic display name wants a look' );
$r = $S::admins_row( array( $admin( 'Patrick', 'test2', $now - $day ) ), $now, $now );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Patrick (a generic name)' ) && false === strpos( $r['note'], 'test2' ), 'a generic login name wants a look, and the login name is never shown' );
$r = $S::admins_row( array( $admin( 'Old Bob', 'bob', $now - 200 * $day ) ), $now, $now );
ok( 'warn' === $r['state'] && false !== strpos( $r['note'], 'Old Bob (no sign-in in 180 days)' ), 'an admin last seen over 180 days ago wants a look' );
$r = $S::admins_row( array( $admin( 'Quiet Ann', 'ann', 0 ) ), $now, $now - 10 * $day );
ok( 'good' === $r['state'], 'no sign-in on record is not flagged until Scout has watched for 180 days' );
$r = $S::admins_row( array( $admin( 'Quiet Ann', 'ann', 0 ) ), $now, $now - 200 * $day );
ok( 'warn' === $r['state'], 'no sign-in in 180 days of watching is flagged' );
$r = $S::admins_row( array( $admin( 'New Ned', 'ned', 0, $now - 5 * $day ) ), $now, $now - 200 * $day );
ok( 'good' === $r['state'], 'a brand new admin is not flagged' );
ok( false === strpos( var_export( $r, true ), '@' ), 'no email address appears in the row' );

// Two-step login.
$a1 = array( 'name' => 'Joy', 'two_step' => true );
$a2 = array( 'name' => 'Sean', 'two_step' => true );
$r  = $S::two_step_row( array( $a1, $a2 ), array() );
ok( 'warn' === $r['state'] && 'None found' === $r['value'], 'no two-step plugin wants a look' );
$r = $S::two_step_row( array( $a1, $a2 ), array( 'Two Factor' ) );
ok( 'good' === $r['state'] && 'On for all 2' === $r['value'], 'every admin using it is ok' );
$r = $S::two_step_row( array( $a1, array( 'name' => 'Sean', 'two_step' => false ) ), array( 'Two Factor' ) );
ok( 'warn' === $r['state'] && 'Off for 1' === $r['value'] && false !== strpos( $r['note'], 'Sean' ), 'an admin without it is named' );
$r = $S::two_step_row( array( $a1, array( 'name' => 'Sean', 'two_step' => null ) ), array( 'miniOrange' ) );
ok( 'warn' === $r['state'] && "Can't tell from here" === $r['value'], 'a plugin that does not say per person reads as can\'t tell, a look and not a fix' );
ok( 'bad' !== $r['state'], 'unknown is never bad' );

// Backups and restore test.
ok( 'good' === $S::backups_row( 'WP Engine' )['state'] && 'WP Engine backs up daily.' === $S::backups_row( 'WP Engine' )['note'], 'WP Engine backups are ok' );
ok( 'good' === $S::backups_row( 'UpdraftPlus' )['state'], 'a named backup from the filter is ok' );
ok( 'warn' === $S::backups_row( '' )['state'], 'unknown backups want a look' );
ok( 'warn' === $S::restore_row( '', $now )['state'] && 'Never' === $S::restore_row( '', $now )['value'], 'a restore never tested wants a look' );
ok( 'good' === $S::restore_row( '2026-09-01', $now )['state'], 'a restore 65 days ago is ok' );
ok( 'warn' === $S::restore_row( '2026-07-01', $now )['state'], 'a restore over 92 days ago wants a look' );
ok( 'good' === $S::restore_row( gmdate( 'Y-m-d', $now - 92 * $day ), $now )['state'], 'exactly 92 days is still ok' );
ok( '' === $S::clean_date( '2026-12-01', $now ) && '' === $S::clean_date( '2026-02-30', $now ) && '' === $S::clean_date( 'soon', $now ), 'future, impossible and junk dates are not saved' );
ok( '2026-10-01' === $S::clean_date( '2026-10-01', $now ), 'a real past date is saved' );

// Uptime alert.
ok( 'warn' === $S::uptime_row( '', '' )['state'] && 'Not set' === $S::uptime_row( '', '' )['value'], 'no uptime answer wants a look' );
ok( 'warn' === $S::uptime_row( 'no', '' )['state'], 'no uptime alert wants a look' );
$r = $S::uptime_row( 'yes', 'UptimeRobot, emails Sean' );
ok( 'good' === $r['state'] && 'Alerts go to UptimeRobot, emails Sean.' === $r['note'], 'an uptime alert is ok and says where it goes' );

// Security headers.
$all = array( 'strict-transport-security' => 'max-age=31536000', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'referrer-policy' => 'strict-origin-when-cross-origin' );
ok( array() === $S::header_gaps( $all ), 'all four headers leave no gaps' );
ok( array() === $S::header_gaps( array( 'Strict-Transport-Security' => '1', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self'", 'Referrer-Policy' => 'no-referrer' ) ), 'CSP frame-ancestors counts for X-Frame-Options, in any case' );
ok( array( 'Strict-Transport-Security', 'Referrer-Policy' ) === $S::header_gaps( array( 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'DENY' ) ), 'missing headers are named in order' );

$r = $S::headers_row( null, $now, true, true, $protect );
ok( 'Checking' === $r['value'] && 'good' === $r['state'], 'before the first check the row says it is checking' );
$GLOBALS['trans'] = array();
ok( null === $S::headers_scan() && false !== $GLOBALS['scheduled'] && $GLOBALS['spawned'], 'with nothing cached, the check runs in the background, never while the page waits' );
$r = $S::headers_row( array( 'at' => $now, 'ok' => false, 'missing' => array() ), $now, true, true, $protect );
ok( 'warn' === $r['state'] && "Couldn't check" === $r['value'], 'a failed check wants a look' );
$r = $S::headers_row( array( 'at' => $now, 'ok' => true, 'missing' => array() ), $now, true, true, $protect );
ok( 'good' === $r['state'] && false !== strpos( $r['note'], 'Checked 2 hours ago.' ), 'all four sent is ok and says when' );
$r = $S::headers_row( array( 'at' => $now, 'ok' => true, 'missing' => array( 'Referrer-Policy' ) ), $now, true, false, $protect );
ok( 'warn' === $r['state'] && '1 missing' === $r['value'] && $protect === ( $r['link'] ?? '' ) && false !== strpos( $r['note'], 'Referrer-Policy' ), 'a missing header is named, pointing to Scout Core\'s switch when it is off' );
$r = $S::headers_row( array( 'at' => $now, 'ok' => true, 'missing' => array( 'Strict-Transport-Security' ) ), $now, true, true, $protect );
ok( 'warn' === $r['state'] && ! isset( $r['link'] ) && false !== strpos( $r['note'], 'Ask the host' ), 'with Scout Core already sending them, the host is asked' );

// Reading admins and Two Factor from WordPress.
$GLOBALS['users'] = array(
	(object) array( 'ID' => 1, 'display_name' => 'Joy McNamara', 'user_login' => 'joy', 'user_registered' => '2024-01-01 00:00:00' ),
	(object) array( 'ID' => 2, 'display_name' => 'Sean McNamara', 'user_login' => 'sean', 'user_registered' => '2024-01-01 00:00:00' ),
);
$GLOBALS['meta'] = array( 1 => array( 'scout_hub_last_login' => $now - $day ), 2 => array( 'session_tokens' => array( 'x' => array( 'login' => $now - 2 * $day ) ) ) );
function get_users( $args ) { return 'administrator' === $args['role'] ? $GLOBALS['users'] : array(); }
function get_user_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_userdata( $id ) { return (object) array( 'ID' => $id ); }
final class Two_Factor_Core { public static function is_user_using_two_factor( $id ) { return 1 === $id; } }
$GLOBALS['opts']['active_plugins'] = array( 'two-factor/two-factor.php', 'scout-build/scout-build.php' );
ok( array( 'Two Factor' ) === $S::two_step_plugins(), 'Two Factor is found among the active plugins' );
$list = $S::admins();
ok( true === $list[0]['two_step'] && false === $list[1]['two_step'], 'Two Factor says per admin who has it on' );
ok( $now - $day === $list[0]['last'] && $now - 2 * $day === $list[1]['last'], 'the last sign-in comes from Scout\'s note or the session list' );
$r = $S::two_step_row( $list, $S::two_step_plugins() );
ok( 'Off for 1' === $r['value'] && false !== strpos( $r['note'], 'Sean McNamara' ), 'the admin without two-step login is named' );
$GLOBALS['opts']['active_plugins'][] = 'miniorange-2-factor-authentication/miniorange_2_factor_settings.php';
$list = $S::admins();
ok( true === $list[0]['two_step'] && null === $list[1]['two_step'], 'with a second plugin that does not say, a "no" from one plugin becomes "can\'t tell"' );

// Who sees the section.
$GLOBALS['can'] = false;
ok( array() === $S::visible_rows(), 'people who cannot manage the site do not get the rows or their count' );

// Words.
$words = '';
foreach ( array( $S::xmlrpc_row( true, false, true, $protect ), $S::file_edit_row( false ), $S::two_step_row( array(), array() ), $S::backups_row( '' ), $S::restore_row( '', $now ), $S::uptime_row( '', '' ) ) as $row ) {
	$words .= $row['label'] . ' ' . $row['value'] . ' ' . $row['note'] . ' ';
}
ok( false === strpos( $words, "\u{2014}" ) && ! preg_match( '/\bclaude\b|optima/i', $words ), 'no em dashes, and no names that do not belong' );

// Clients never see the two rows only Scout can clear.
ok( ! Scout_Hub_Security::client_row( Scout_Hub_Security::restore_row( '', time() ) ) && ! Scout_Hub_Security::client_row( Scout_Hub_Security::uptime_row( '', '' ) ), 'Restore test and Uptime alert are Scout only' );
ok( Scout_Hub_Security::client_row( Scout_Hub_Security::file_edit_row( false ) ), 'File editing shows to clients' );

echo $fail ? "\n$fail FAILED\n" : "\nAll security baseline checks passed\n";
exit( $fail ? 1 : 0 );
