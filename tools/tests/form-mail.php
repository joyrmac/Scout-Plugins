<?php
// Form emails sent, in Site status: every Gravity Forms notification try is
// noted (never who it went to or what it said) and read back as one row.
// Runs without WordPress: php tools/tests/form-mail.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['opts']    = array( 'admin_email' => 'owner@example-firm.com' );
$GLOBALS['actions'] = array();
$GLOBALS['mail']    = array();
$GLOBALS['mail_ok'] = true;
$GLOBALS['saved']   = array();
function add_action( $hook, $cb ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function remove_action( $hook, $cb ) { $GLOBALS['actions'][ $hook ] = array_values( array_filter( $GLOBALS['actions'][ $hook ] ?? array(), static fn( $c ) => $c !== $cb ) ); }
function add_filter( $hook, $cb ) { add_action( $hook, $cb ); }
function remove_filter( $hook, $cb ) { remove_action( $hook, $cb ); }
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; $GLOBALS['saved'][ $k ] = $autoload; return true; }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example-firm.com' . $p; }
function get_bloginfo( $k ) { return 'Example Firm'; }
function wp_specialchars_decode( $s, $q = 0 ) { return $s; }
function current_user_can( $c ) { return true; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
class WP_Error { private $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['mail'][] = array( $to, $subject, $body );
	if ( ! $GLOBALS['mail_ok'] ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate as joy@scoutraleigh.com.' ) );
	}
	return $GLOBALS['mail_ok'];
}
require __DIR__ . '/../../scout-build/includes/class-scout-hub-form-mail.php';
$M = 'Scout_Hub_Form_Mail';
$M::boot();
$now   = strtotime( '2026-11-05 15:00:00 UTC' );
$day   = DAY_IN_SECONDS;
$title = static fn( $id ) => array( 1 => 'Contact', 2 => 'Quote' )[ $id ] ?? "Form $id";
$reset = static function () { unset( $GLOBALS['opts']['scout_hub_form_mail'] ); };

// One Gravity Forms notification, start to finish, the way Gravity Forms fires the hooks.
$entry = array( 'id' => 9, 'form_id' => '1', '1' => 'Pat Visitor', '2' => 'pat@visitor.example', '3' => 'Call me about my roof' );
$notification = array( 'id' => 'abc', 'name' => 'Admin notification', 'to' => 'joy@scoutraleigh.com' );
$email = array( 'to' => 'joy@scoutraleigh.com', 'subject' => 'New message from Pat', 'message' => 'Call me about my roof', 'headers' => array(), 'attachments' => array(), 'abort_email' => false );
$send = static function ( bool $ok, string $error = '' ) use ( $M, $email, $notification, $entry ) {
	$M::before_send( $email, 'html', $notification, $entry );
	if ( ! $ok && '' !== $error ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error ) );
	}
	do_action( 'gform_after_email', $ok, $email['to'], $email['subject'], $email['message'], '', array(), 'html', 'pat@visitor.example', 'Pat', '', 'pat@visitor.example', $entry, '' );
};

$send( true );
$d = $M::data();
ok( 1 === count( $d['log'] ) && true === $d['log'][0]['ok'] && 1 === $d['log'][0]['f'] && 'Admin notification' === $d['log'][0]['n'] && 'form' === $d['log'][0]['k'], 'a sent notification is noted with its form and name' );
ok( false === $GLOBALS['saved']['scout_hub_form_mail'], 'the option is not autoloaded' );

$send( false, 'SMTP Error: The following recipients failed: joy@scoutraleigh.com: <joy@scoutraleigh.com> mailbox full' );
$d   = $M::data();
$row = $d['log'][0];
ok( false === $row['ok'] && 'SMTP Error: The following recipients failed: [address]: [address] mailbox full' === $row['e'], 'a failure keeps the error with every address taken out: ' . $row['e'] );
ok( $row === $d['last_fail'], 'the last failure is kept on its own, so the log cap never loses it' );

$stored = var_export( $GLOBALS['opts']['scout_hub_form_mail'], true );
ok( false === strpos( $stored, '@' ) && false === strpos( $stored, 'Pat' ) && false === strpos( $stored, 'roof' ) && false === strpos( $stored, 'New message' ), 'no recipient, subject, message, entry or visitor data is stored' );

// A failure Gravity Forms reports itself, when wp_mail gave no reason.
$M::before_send( $email, 'html', $notification, $entry );
do_action( 'gform_send_email_failed', new WP_Error( 'x', 'Could not instantiate mail function for owner@example-firm.com' ), $email, $entry );
do_action( 'gform_after_email', false );
ok( 'Could not instantiate mail function for [address]' === $M::data()['log'][0]['e'], 'gform_send_email_failed fills in the reason, and a short argument list still works' );

// A cancelled email is not counted, and mail outside a notification is ignored.
$M::before_send( array( 'abort_email' => true ) + $email, 'html', $notification, $entry );
do_action( 'wp_mail_failed', new WP_Error( 'x', 'another plugin failed' ) );
$M::before_send( $email, 'html', $notification, $entry );
do_action( 'gform_after_email', true, '', '', '', '', array(), 'html', '', '', '', '', $entry, '' );
ok( '' === $M::data()['log'][0]['e'] && true === $M::data()['log'][0]['ok'], 'a wp_mail failure outside a Gravity Forms notification is not pinned on it' );

// The cap of 50.
$reset();
for ( $i = 0; $i < 60; $i++ ) {
	$M::record( 'form', 1, 'Admin notification', true, '', $now - 60 + $i );
}
$d = $M::data();
ok( 50 === count( $d['log'] ) && $now - 1 === $d['log'][0]['t'], 'the log keeps the newest 50 tries, newest first' );
ok( 60 === $d['tally']['1'][ gmdate( 'Y-m-d', $now ) ][0], 'the daily count still counts all 60' );

// The 7-day and 30-day windows.
$reset();
$M::record( 'form', 1, 'Admin notification', true, '', $now );
$M::record( 'form', 1, 'Admin notification', true, '', $now - 6 * $day );
$M::record( 'form', 2, 'Admin notification', true, '', $now - 7 * $day );
$M::record( 'form', 2, 'Admin notification', false, 'timeout', $now - 29 * $day );
$M::record( 'form', 2, 'Admin notification', true, '', $now - 31 * $day );
$c = $M::counts( $M::data(), $now );
ok( 2 === $c['sent7'] && 0 === $c['failed7'], 'the 7-day window covers today and the 6 days before: ' . $c['sent7'] );
ok( 3 === $c['sent30'] && 1 === $c['failed30'], 'the 30-day window covers 30 days' );
$M::record( 'form', 1, 'Admin notification', true, '', $now );
ok( ! isset( $M::data()['tally']['2'][ gmdate( 'Y-m-d', $now - 31 * $day ) ] ), 'days older than 30 are dropped from the count' );
ok( false === $c['failed24'], 'a failure 29 days ago is not in the last 24 hours' );

// Each row state.
$reset();
$r = $M::build_row( $M::data(), $now, 0, $title );
ok( 'Form emails sent' === $r['label'] && 'good' === $r['state'] && 'None yet' === $r['value'] && 0 === strpos( $r['note'], 'No form emails yet.' ), 'nothing noted and no entries: a calm "No form emails yet"' );

for ( $i = 0; $i < 14; $i++ ) {
	$M::record( 'form', 1, 'Admin notification', true, '', $now - $i * 3600 );
}
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'good' === $r['state'] && '14 sent' === $r['value'] && '14 sent in the last 7 days, none failed.' === $r['note'], 'all sent: ok, "14 sent in the last 7 days, none failed"' );

$M::record( 'form', 1, 'Admin notification', false, 'Could not authenticate', $now - 3 * $day );
$M::record( 'form', 1, 'Admin notification', false, 'SMTP connect() failed', $now - 2 * $day );
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'warn' === $r['state'] && '2 of 16 failed' === $r['value'], 'failures in 7 days, none in the last day: look, "2 of 16 failed"' );
ok( 0 === strpos( $r['note'], '2 of 16 failed. Last: Contact, Admin notification, ' . gmdate( 'M j', $now - 2 * $day ) . ': SMTP connect() failed.' ), 'the note names the last failure: ' . $r['note'] );
ok( false !== strpos( $r['note'], 'Site email' ) && '/wp-admin/admin.php?page=gf_edit_forms&view=settings&subview=notification&id=1' === $r['link'], 'the fix points to Site email and links to that form\'s notifications' );

$M::record( 'form', 2, 'Office copy', false, 'mailbox full', $now - 3600 );
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'bad' === $r['state'] && false !== strpos( $r['note'], 'Last: Quote, Office copy' ) && false !== strpos( $r['link'], 'id=2' ), 'a failure in the last 24 hours is bad, so it reaches Today' );

$reset();
$GLOBALS['opts']['scout_hub_form_mail'] = array( 'since' => $now - 40 * $day );
$r = $M::build_row( $M::data(), $now, 6, $title );
ok( 'warn' === $r['state'] && 'None sent' === $r['value'] && 0 === strpos( $r['note'], 'No form emails in 30 days, but the forms got 6 entries. Notifications may be off.' ), 'entries but no emails in 30 days: look, notifications may be off' );
$GLOBALS['opts']['scout_hub_form_mail'] = array( 'since' => $now - 3 * $day );
$r = $M::build_row( $M::data(), $now, 1, $title );
ok( 'warn' === $r['state'] && 0 === strpos( $r['note'], 'No form emails since ' . gmdate( 'M j', $now - 3 * $day ) . ', but the forms got 1 entry.' ), 'when counting started recently, the note says since when' );
$r = $M::build_row( $M::data(), $now, 0, $title );
ok( 'good' === $r['state'] && 'None yet' === $r['value'], 'no entries either: calm' );
$r = $M::build_row( $M::data(), $now, null, $title );
ok( 'good' === $r['state'], 'when Gravity Forms can\'t count entries, no guess' );

$reset();
$M::record( 'form', 1, 'Admin notification', true, '', $now - 10 * $day );
$r = $M::build_row( $M::data(), $now, 5, $title );
ok( 'good' === $r['state'] && 'None this week' === $r['value'] && false !== strpos( $r['note'], '1 sent in the last 30' ), 'quiet week after sends earlier in the month: ok, says so' );

// The test send.
$reset();
$ok = $M::test_send();
$m  = $GLOBALS['mail'][0] ?? array( '', '', '' );
ok( $ok && 'owner@example-firm.com' === $m[0] && 'Test from Example Firm Site status' === $m[1], 'without Scout Core the test goes to the admin email, with the site name in the subject' );
ok( false !== strpos( $m[2], 'No form was sent' ), 'the test body says no form was sent' );
$d = $M::data();
ok( 'test' === $d['log'][0]['k'] && true === $d['log'][0]['ok'] && 0 === $d['log'][0]['f'] && array() === $d['tally'], 'the test is noted as kind "test" and never counted as a form email' );
ok( 1 === count( $GLOBALS['actions']['wp_mail_failed'] ?? array() ) && array() === ( $GLOBALS['actions']['wp_mail_content_type'] ?? array() ), 'the test removes its own hooks afterwards' );
$r = $M::build_row( $d, time(), 0, $title );
ok( false !== strpos( $r['note'], 'Last test: sent' ), 'the row mentions the last test' );

if ( true ) { // Declared here, not up front, so it only exists from this point on.
	function scout_core_notice_email() { return 'notices@example-firm.com'; }
}
$GLOBALS['mail_ok'] = false;
ok( false === $M::test_send() && 'notices@example-firm.com' === end( $GLOBALS['mail'] )[0], 'with Scout Core, the test goes to the shared notice address' );
$d = $M::data();
ok( false === $d['log'][0]['ok'] && 'SMTP Error: Could not authenticate as [address].' === $d['log'][0]['e'], 'a failed test is noted with its reason, address taken out' );
$r = $M::build_row( $d, time(), 0, $title );
ok( 'warn' === $r['state'] && 'Test failed' === $r['value'] && 'bad' !== $r['state'], 'a failed test wants a look, but only form emails count as bad' );
ok( 1 === count( $GLOBALS['actions']['wp_mail_failed'] ?? array() ), 'only the always-on hook is left' );

// Nothing shows without Gravity Forms.
ok( null === $M::row(), 'no row on a site without Gravity Forms' );
ob_start();
$M::render_test();
ok( '' === ob_get_clean(), 'no test box without Gravity Forms or for non-operators' );

// With Gravity Forms: counting starts the first time the row is read, and entries are only counted from then.
if ( true ) { // Declared here so the checks above run without it.
	class GFAPI {
		public static $asked = array();
		public static function get_forms() { return array( array( 'id' => 1, 'title' => 'Contact' ) ); }
		public static function get_form( $id ) { return array( 'id' => $id, 'title' => 'Contact' ); }
		public static function count_entries( $forms, $criteria ) { self::$asked[] = $criteria; return 4; }
	}
}
$reset();
$r = $M::row();
ok( is_array( $r ) && 'good' === $r['state'] && $M::data()['since'] > 0 && array() === GFAPI::$asked, 'the first read starts counting and never treats older entries as missed emails' );
$GLOBALS['opts']['scout_hub_form_mail']['since'] = time() - 2 * $day;
$r = $M::row();
ok( 'warn' === $r['state'] && 'active' === ( GFAPI::$asked[0]['status'] ?? '' ) && gmdate( 'Y-m-d H:i:s', time() - 2 * $day ) === GFAPI::$asked[0]['start_date'], 'entries since counting started, with no emails, want a look (spam and trash left out)' );
$M::record( 'form', 1, 'Admin notification', true );
GFAPI::$asked = array();
ok( 'good' === $M::row()['state'] && array() === GFAPI::$asked, 'once an email is noted, entries are not counted at all' );

// Words.
$words = '';
foreach ( array( $M::build_row( array( 'since' => $now - 40 * $day ) + $M::data(), $now, 3, $title ), $M::build_row( $M::data(), $now, 0, $title ) ) as $row ) {
	$words .= $row['label'] . ' ' . $row['value'] . ' ' . $row['note'] . ' ';
}
ok( false === strpos( $words, "\u{2014}" ) && ! preg_match( '/\bclaude\b|optima/i', $words ), 'no em dashes, and no names that do not belong' );

echo $fail ? "\n$fail FAILED\n" : "\nAll form email checks passed\n";
exit( $fail ? 1 : 0 );
