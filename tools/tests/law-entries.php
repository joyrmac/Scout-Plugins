<?php
// Scout Law Firm Tools: form entries that delete themselves. Runs without WordPress:
//   php tools/tests/law-entries.php
// The stand-in Gravity Forms below ignores the date and star filters on purpose,
// so the checks prove the plugin's own test of each entry, not Gravity Forms'.
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['opts'] = array();
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }

final class GFAPI {
	public static $forms   = array();
	public static $entries = array(); // id => entry.
	public static $refuse  = array(); // ids delete_entry fails on.
	public static $calls   = 0;
	public static function get_forms() { return array_values( self::$forms ); }
	public static function get_form( $id ) { return self::$forms[ $id ] ?? false; }
	public static function get_entries( $form_id, $search, $sorting, $paging ) {
		self::$calls++;
		$rows = array_values( array_filter( self::$entries, function ( $e ) use ( $form_id, $search ) {
			return $e['form_id'] === $form_id && $e['status'] === $search['status'];
		} ) );
		usort( $rows, function ( $a, $b ) { return strcmp( $a['date_created'], $b['date_created'] ); } );
		return array_slice( $rows, $paging['offset'], $paging['page_size'] );
	}
	public static function delete_entry( $id ) {
		if ( in_array( $id, self::$refuse, true ) ) { return new stdClass(); }
		unset( self::$entries[ $id ] );
		return true;
	}
}

require __DIR__ . '/../../scout-law/includes/class-scout-law-entries.php';
$E   = 'Scout_Law_Entries';
$now = strtotime( '2026-11-04 12:00:00 UTC' );
$day = 86400;

// Settings.
$s = $E::settings();
ok( true === $s['on'] && 90 === $s['days'] && array() === $s['keep'], 'defaults: on, 90 days, no forms kept' );
ok( 90 === $E::days(), 'days() reads 90 for the privacy policy' );
$GLOBALS['opts'][ $E::OPTION ] = array( 'on' => true, 'days' => 45, 'keep' => array( '3', 0 ) );
$s = $E::settings();
ok( 90 === $s['days'] && array( 3 ) === $s['keep'], 'a time not on the list falls back to 90; kept forms read as ids' );
$GLOBALS['opts'][ $E::OPTION ] = array( 'on' => false );
ok( 0 === $E::days(), 'days() is 0 when deleting is off' );
unset( $GLOBALS['opts'][ $E::OPTION ] );

// Which forms send email.
$mail = array( 'n1' => array( 'isActive' => true, 'event' => 'form_submission', 'to' => '{admin_email}' ) );
ok( $E::emails_someone( array( 'notifications' => $mail ) ), 'an active submission email counts' );
ok( $E::emails_someone( array( 'notifications' => array( array( 'to' => 'a@b.c' ) ) ) ), 'a notification with no isActive or event key counts (Gravity Forms treats it as on)' );
ok( ! $E::emails_someone( array( 'notifications' => array( array( 'isActive' => false, 'to' => 'a@b.c' ) ) ) ), 'a switched-off email does not count' );
ok( ! $E::emails_someone( array( 'notifications' => array( array( 'event' => 'form_saved', 'to' => 'a@b.c' ) ) ) ), 'an email sent on another event does not count' );
ok( ! $E::emails_someone( array( 'notifications' => array( array( 'to' => '  ' ) ) ) ), 'an email with nobody to send to does not count' );
ok( ! $E::emails_someone( array() ), 'no notifications: emails no one' );

// The plan for each form.
GFAPI::$forms = array(
	1 => array( 'id' => 1, 'title' => 'Contact', 'notifications' => $mail ),
	2 => array( 'id' => 2, 'title' => 'Quiet', 'notifications' => array() ),
	3 => array( 'id' => 3, 'title' => 'Intake', 'notifications' => $mail ),
	4 => array( 'id' => 4, 'title' => 'Own rule', 'notifications' => $mail, 'personalData' => array( 'retention' => array( 'policy' => 'delete', 'retain_entries_days' => 30 ) ) ),
);
$plan = $E::plan( $E::forms(), array( 'on' => true, 'days' => 90, 'keep' => array( 3 ) ) );
ok( 'delete' === $plan[1]['does'] && 'no-email' === $plan[2]['does'] && 'kept' === $plan[3]['does'] && 'gravity' === $plan[4]['does'], 'plan: delete, kept for no email, kept by choice, left to Gravity Forms' );

// Each entry.
$cut = $E::cutoff( $now, 90 );
ok( $E::expired( array( 'date_created' => gmdate( 'Y-m-d H:i:s', $cut - 60 ) ), $cut ), 'an entry a minute past 90 days has expired' );
ok( ! $E::expired( array( 'date_created' => gmdate( 'Y-m-d H:i:s', $cut + 60 ) ), $cut ), 'an entry a minute inside 90 days stays' );
ok( ! $E::expired( array( 'date_created' => '2020-01-01 00:00:00', 'is_starred' => '1' ), $cut ), 'a starred entry stays however old' );
ok( ! $E::expired( array( 'date_created' => '' ), $cut ), 'an entry with no date stays' );

// A site full of entries.
$id  = 0;
$add = function ( $form, $age_days, $status = 'active', $star = 0 ) use ( &$id, $now, $day ) {
	$id++;
	GFAPI::$entries[ $id ] = array( 'id' => $id, 'form_id' => $form, 'status' => $status, 'is_starred' => $star, 'date_created' => gmdate( 'Y-m-d H:i:s', $now - $age_days * $day ) );
	return $id;
};
for ( $i = 0; $i < 70; $i++ ) { $add( 1, 100 + $i ); }   // 70 old Contact entries (more than one page).
$star  = $add( 1, 400, 'active', 1 );                    // Old but starred.
$fresh = $add( 1, 10 );                                  // New.
$spam  = $add( 1, 200, 'spam' );                         // Old spam.
$trash = $add( 1, 200, 'trash' );                        // Old trash.
$quiet = $add( 2, 300 );                                 // Form that emails no one.
$kept  = $add( 3, 300 );                                 // Form the firm keeps.
$own   = $add( 4, 300 );                                 // Form with its own rule.

// The notice period.
$GLOBALS['opts'][ $E::STATE ] = array( 'first_seen' => $now - 13 * $day );
ok( array() === $E::run( $now ) && 77 === count( GFAPI::$entries ), 'day 13 of the 14-day notice: nothing is deleted' );
$GLOBALS['opts'][ $E::STATE ] = array();
ok( array() === $E::run( $now ) && $now === $GLOBALS['opts'][ $E::STATE ]['first_seen'], 'the first run on a site starts the notice and deletes nothing' );
ok( $now + 14 * $day === $E::starts( $now ), 'deleting starts 14 days after the plugin first runs' );

// The first real night, with one entry Gravity Forms refuses to delete.
$GLOBALS['opts'][ $E::STATE ] = array( 'first_seen' => $now - 15 * $day );
$GLOBALS['opts'][ $E::OPTION ] = array( 'on' => true, 'days' => 90, 'keep' => array( 3 ) );
GFAPI::$refuse = array( 5 );
$res = $E::run( $now );
ok( array( 1 => 71 ) === $res, 'one night deletes the 69 deletable old entries plus old spam and trash, on the Contact form only' );
ok( isset( GFAPI::$entries[ $star ], GFAPI::$entries[ $fresh ], GFAPI::$entries[5] ), 'the starred entry, the new entry and the refused entry are still there' );
ok( ! isset( GFAPI::$entries[ $spam ] ) && ! isset( GFAPI::$entries[ $trash ] ), 'old spam and trash are gone' );
ok( isset( GFAPI::$entries[ $quiet ], GFAPI::$entries[ $kept ], GFAPI::$entries[ $own ] ), 'the quiet form, the kept form and the form with its own rule are untouched' );
ok( 71 === $GLOBALS['opts'][ $E::LOG ][0]['total'] && $now === $GLOBALS['opts'][ $E::STATE ]['last_run'], 'the night is recorded with a count, never names' );
ok( GFAPI::$calls < 20, 'a refused entry never makes the night loop' );

// The next night: nothing left, nothing recorded.
GFAPI::$refuse = array();
$res = $E::run( $now + $day );
ok( array( 1 => 1 ) === $res && ! isset( GFAPI::$entries[5] ), 'the refused entry goes the next night' );
$before = count( $GLOBALS['opts'][ $E::LOG ] );
$E::run( $now + 2 * $day );
ok( count( $GLOBALS['opts'][ $E::LOG ] ) === $before, 'a night that deletes nothing adds no record line' );

// Off means off.
$add( 1, 500 );
$GLOBALS['opts'][ $E::OPTION ]['on'] = false;
ok( array() === $E::run( $now + 3 * $day ), 'with deleting off, nothing is deleted' );

// A big backlog is spread over nights.
$GLOBALS['opts'][ $E::OPTION ]['on'] = true;
for ( $i = 0; $i < 620; $i++ ) { $add( 1, 120 ); }
$res = $E::run( $now + 4 * $day );
ok( 500 === $res[1], 'a backlog stops at 500 entries a night, so the run never times out' );

// WordPress calls a cron action with one empty string. The hooked job must take it.
try { call_user_func( array( $E, 'nightly' ), '' ); ok( true, 'the nightly job runs when WordPress passes it an empty string' ); } catch ( Throwable $t ) { ok( false, 'the nightly job runs when WordPress passes it an empty string: ' . $t->getMessage() ); }

echo $fail ? "\n$fail FAILED\n" : "\nAll form entry checks passed\n";
exit( $fail ? 1 : 0 );
