<?php
// Scout Booking reads the owner's own calendar and leaves busy times off the booking page.
// Runs without WordPress: php tools/tests/booking-busy.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );

// ---------- WordPress stubs ----------
$GLOBALS['opts']  = array();
$GLOBALS['trans'] = array();
$GLOBALS['now']   = ( new DateTimeImmutable( '2026-11-05 12:00:00', new DateTimeZone( 'America/New_York' ) ) )->getTimestamp();
$GLOBALS['http']  = array();
$GLOBALS['asked'] = array();
function add_action() {}
function add_filter() {}
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function wp_date( $f, $ts ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() )->format( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function add_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
function wp_next_scheduled() { return false; }
function wp_schedule_event() {}
function wp_clear_scheduled_hook() {}
function home_url() { return 'https://example.com/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
final class WP_Error {}
function wp_remote_get( $url, $args ) {
	$GLOBALS['asked'][] = array( $url, $args );
	return $GLOBALS['http'][ $url ] ?? new WP_Error();
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ $h ] ?? ''; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

final class Scout_Booking {
	const HOLDING = array( 'requested', 'approved', 'pending', 'confirmed', 'blocked' );
	public static $rows   = array();
	public static $buffer = 0;
	public static function now() { return ( new DateTimeImmutable( '@' . $GLOBALS['now'] ) )->setTimezone( wp_timezone() ); }
	public static function scout_hours_on() { return false; }
	public static function settings() {
		$week = array();
		foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $d ) {
			$week[ $d ] = array( 'open' => true, 'from' => '09:00', 'to' => '17:00' );
		}
		return array( 'notice_hours' => 0, 'days_ahead' => 60, 'step' => 30, 'hours' => $week );
	}
	public static function between() { return self::$rows; }
	public static function holds() { return true; }
	public static function at( $s ) { return DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $s, wp_timezone() ); }
}
final class Scout_Booking_Admin {
	public static function url( $a = array(), $h = '', $v = '' ) { return '/wp-admin/admin.php?page=scout-booking-' . $v . '#' . $h; }
}
require __DIR__ . '/../../scout-booking/includes/class-scout-booking-busy.php';
require __DIR__ . '/../../scout-booking/includes/class-scout-booking-slots.php';
$B  = 'Scout_Booking_Busy';
$tz = wp_timezone();
function ny( $s ) { return ( new DateTimeImmutable( $s, wp_timezone() ) )->getTimestamp(); }
function has_block( $blocks, $a, $z ) { foreach ( $blocks as $b ) { if ( $b[0] === ny( $a ) && $b[1] === ny( $z ) ) { return true; } } return false; }
function starts_on( $blocks, $day ) { return array_values( array_filter( $blocks, static fn( $b ) => wp_date( 'Y-m-d', $b[0] ) === $day ) ); }

// ---------- the fixture: a calendar with every case ----------
$ics = implode(
	"\r\n",
	array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Google Inc//Google Calendar 70.9054//EN',
		'BEGIN:VTIMEZONE',
		'TZID:America/Chicago',
		'BEGIN:STANDARD',
		'DTSTART:19701101T020000',
		'TZOFFSETFROM:-0500',
		'TZOFFSETTO:-0600',
		'END:STANDARD',
		'END:VTIMEZONE',
		// A. Timed, with TZID (Chicago): 10 to 11 there is 11 to 12 in New York.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/Chicago:20261110T100000',
		'DTEND;TZID=America/Chicago:20261110T110000',
		'SUMMARY:Secret therapy with Jane Private',
		'ATTENDEE;CN=Jane Private:mailto:jane@example.org',
		'LOCATION:1 Hidden Lane',
		'DESCRIPTION:Very private notes',
		'UID:a@google.com',
		'END:VEVENT',
		// B. UTC: 15:00Z is 10:00 in New York.
		'BEGIN:VEVENT',
		'DTSTART:20261111T150000Z',
		'DTEND:20261111T160000Z',
		'SUMMARY:Dentist',
		'UID:b@google.com',
		'END:VEVENT',
		// C. All-day and busy.
		'BEGIN:VEVENT',
		'DTSTART;VALUE=DATE:20261112',
		'DTEND;VALUE=DATE:20261113',
		'SUMMARY:Conference',
		'UID:c@google.com',
		'END:VEVENT',
		// D. All-day and marked free.
		'BEGIN:VEVENT',
		'DTSTART;VALUE=DATE:20261113',
		'DTEND;VALUE=DATE:20261114',
		'TRANSP:TRANSPARENT',
		'SUMMARY:Birthday',
		'UID:d@google.com',
		'END:VEVENT',
		// E. Weekly on Tuesday and Thursday, six times, one skipped.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20261103T140000',
		'DTEND;TZID=America/New_York:20261103T150000',
		'RRULE:FREQ=WEEKLY;BYDAY=TU,TH;COUNT=6',
		'EXDATE;TZID=America/New_York:20261112T140000',
		'SUMMARY:Standing client',
		'UID:e@google.com',
		'END:VEVENT',
		// F. Cancelled.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20261110T090000',
		'DTEND;TZID=America/New_York:20261110T100000',
		'STATUS:CANCELLED',
		'UID:f@google.com',
		'END:VEVENT',
		// G. Past the 60 days, and already over.
		'BEGIN:VEVENT',
		'DTSTART:20270201T150000Z',
		'DTEND:20270201T160000Z',
		'UID:g1@google.com',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'DTSTART:20261001T150000Z',
		'DTEND:20261001T160000Z',
		'UID:g2@google.com',
		'END:VEVENT',
		// I. Daily every other day until Nov 9, with a folded RRULE and a DURATION.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20261106T080000',
		'DURATION:PT30M',
		'RRULE:FREQ=DAILY;INTER',
		' VAL=2;UNTIL=20261109T235959Z',
		'SUMMARY:A long title that is folded onto a second line by the calendar app',
		' and keeps going',
		'UID:i@google.com',
		'END:VEVENT',
		// J. Weekly Mondays, three times; the Nov 16 one moved to 1 pm.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20261109T090000',
		'DTEND;TZID=America/New_York:20261109T093000',
		'RRULE:FREQ=WEEKLY;COUNT=3',
		'UID:j@google.com',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'RECURRENCE-ID;TZID=America/New_York:20261116T090000',
		'DTSTART;TZID=America/New_York:20261116T130000',
		'DTEND;TZID=America/New_York:20261116T133000',
		'UID:j@google.com',
		'END:VEVENT',
		// K. One of our own bookings, copied into the owner's calendar.
		'BEGIN:VEVENT',
		'UID:booking-abc123def456@example.com',
		'DTSTART:20261118T150000Z',
		'DTEND:20261118T160000Z',
		'END:VEVENT',
		// L. Monthly on the second Tuesday.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20261013T160000',
		'DTEND;TZID=America/New_York:20261013T170000',
		'RRULE:FREQ=MONTHLY;BYDAY=2TU',
		'UID:l@google.com',
		'END:VEVENT',
		// M. Monthly on the 15th, every month, starting long ago.
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/New_York:20200915T080000',
		'DTEND;TZID=America/New_York:20200915T083000',
		'RRULE:FREQ=MONTHLY;BYMONTHDAY=15',
		'UID:m@google.com',
		'END:VEVENT',
		// N. Outlook's Windows zone name.
		'BEGIN:VEVENT',
		'DTSTART;TZID="Pacific Standard Time":20261120T090000',
		'DTEND;TZID="Pacific Standard Time":20261120T100000',
		'UID:n@outlook.com',
		'END:VEVENT',
		'END:VCALENDAR',
		'',
	)
);
$from   = $GLOBALS['now'];
$to     = $from + 60 * 86400;
$skip   = '/^booking-[a-z0-9]+@example\.com$/i';
$blocks = $B::parse( $ics, $tz, $from, $to, $skip );

ok( has_block( $blocks, '2026-11-10 11:00', '2026-11-10 12:00' ), 'a timed event with TZID lands at the right New York time' );
ok( has_block( $blocks, '2026-11-11 10:00', '2026-11-11 11:00' ), 'a UTC event lands at the right New York time' );
ok( has_block( $blocks, '2026-11-12 00:00', '2026-11-13 00:00' ), 'a busy all-day event blocks the whole day' );
ok( ! starts_on( $blocks, '2026-11-13' ), 'an all-day event marked free (TRANSP:TRANSPARENT) blocks nothing' );
ok( ! has_block( $blocks, '2026-11-10 09:00', '2026-11-10 10:00' ), 'a cancelled event blocks nothing' );
ok( has_block( $blocks, '2026-11-05 14:00', '2026-11-05 15:00' ) && has_block( $blocks, '2026-11-10 14:00', '2026-11-10 15:00' ) && has_block( $blocks, '2026-11-17 14:00', '2026-11-17 15:00' ) && has_block( $blocks, '2026-11-19 14:00', '2026-11-19 15:00' ), 'a weekly event with BYDAY=TU,TH repeats on both days' );
// The all-day block on Nov 12 hides the EXDATE in the full calendar, so check the series alone.
$only_e = $B::parse( implode( "\r\n", array( 'BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;TZID=America/New_York:20261103T140000', 'DTEND;TZID=America/New_York:20261103T150000', 'RRULE:FREQ=WEEKLY;BYDAY=TU,TH;COUNT=6', 'EXDATE;TZID=America/New_York:20261112T140000', 'UID:e@google.com', 'END:VEVENT', 'END:VCALENDAR' ) ), $tz, $from, $to );
ok( 4 === count( $only_e ) && ! has_block( $only_e, '2026-11-12 14:00', '2026-11-12 15:00' ), 'EXDATE removes Nov 12, COUNT=6 stops after Nov 19, and the past Nov 3 is dropped: ' . count( $only_e ) . ' times' );
ok( ! has_block( $only_e, '2026-11-24 14:00', '2026-11-24 15:00' ), 'COUNT counts the skipped date, so there is no seventh time' );
ok( has_block( $blocks, '2026-11-06 08:00', '2026-11-06 08:30' ) && has_block( $blocks, '2026-11-08 08:00', '2026-11-08 08:30' ) && ! starts_on( $blocks, '2026-11-07' ), 'DAILY with INTERVAL=2, DURATION and a folded line' );
ok( ! has_block( $blocks, '2026-11-10 08:00', '2026-11-10 08:30' ), 'UNTIL stops the daily event after Nov 9' );
ok( has_block( $blocks, '2026-11-09 09:00', '2026-11-09 09:30' ) && has_block( $blocks, '2026-11-16 13:00', '2026-11-16 13:30' ) && ! has_block( $blocks, '2026-11-16 09:00', '2026-11-16 09:30' ) && has_block( $blocks, '2026-11-23 09:00', '2026-11-23 09:30' ), 'a moved time of a series (RECURRENCE-ID) replaces the usual time' );
ok( ! starts_on( $blocks, '2026-11-18' ), 'our own bookings in the owner\'s calendar are not counted twice' );
ok( has_block( $blocks, '2026-11-10 16:00', '2026-11-10 17:00' ) && has_block( $blocks, '2026-12-08 16:00', '2026-12-08 17:00' ), 'MONTHLY with BYDAY=2TU finds the second Tuesday' );
ok( has_block( $blocks, '2026-11-15 08:00', '2026-11-15 08:30' ) && has_block( $blocks, '2026-12-15 08:00', '2026-12-15 08:30' ), 'MONTHLY by month day, started years ago' );
ok( has_block( $blocks, '2026-11-20 12:00', '2026-11-20 13:00' ), 'a Windows zone name (Pacific Standard Time) is read as Los Angeles' );
$late = array_filter( $blocks, static fn( $b ) => $b[0] >= $to || $b[1] <= $from );
ok( ! $late && ! starts_on( $blocks, '2027-02-01' ) && ! starts_on( $blocks, '2026-10-01' ), 'only the next 60 days are kept' );
$flat = true;
foreach ( $blocks as $b ) {
	$flat = $flat && is_array( $b ) && 2 === count( $b ) && is_int( $b[0] ) && is_int( $b[1] ) && $b[1] > $b[0];
}
$saved = serialize( $blocks );
ok( $flat, 'each busy block is only a start and an end, as whole seconds' );
ok( false === stripos( $saved, 'Jane' ) && false === stripos( $saved, 'therapy' ) && false === stripos( $saved, 'example.org' ) && false === stripos( $saved, 'Hidden' ) && false === stripos( $saved, 'private notes' ), 'titles, attendees, places and notes are never kept' );
$sorted = $blocks;
usort( $sorted, static fn( $a, $b ) => $a[0] <=> $b[0] );
ok( $sorted === $blocks, 'blocks come out soonest first' );

// Daylight saving: a weekly 9 am stays at 9 am across the November change.
$dst = $B::parse( "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;TZID=America/New_York:20261026T090000\r\nDTEND;TZID=America/New_York:20261026T100000\r\nRRULE:FREQ=WEEKLY\r\nUID:x\r\nEND:VEVENT\r\nEND:VCALENDAR", $tz, ny( '2026-10-25 00:00' ), $to );
ok( has_block( $dst, '2026-10-26 09:00', '2026-10-26 10:00' ) && has_block( $dst, '2026-11-02 09:00', '2026-11-02 10:00' ), 'a weekly event keeps its wall-clock time across daylight saving' );
// All-day across the spring change still counts whole days.
$spring = $B::parse( "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270313\r\nDTEND;VALUE=DATE:20270315\r\nUID:y\r\nEND:VEVENT\r\nEND:VCALENDAR", $tz, ny( '2027-03-01 00:00' ), ny( '2027-04-01 00:00' ) );
ok( has_block( $spring, '2027-03-13 00:00', '2027-03-15 00:00' ), 'a two-day all-day event over the spring change blocks both days' );
// Overlapping blocks merge.
ok( array( array( 1, 5 ), array( 7, 9 ) ) === $B::merge( array( array( 3, 5 ), array( 1, 4 ), array( 7, 9 ), array( 5, 5 ) ) ), 'overlapping and touching blocks merge' );

// ---------- addresses ----------
ok( 'https://calendar.google.com/calendar/ical/me%40gmail.com/private-abc123/basic.ics' === $B::clean_url( ' https://calendar.google.com/calendar/ical/me%40gmail.com/private-abc123/basic.ics ' ), 'a Google secret address is kept as is, %40 included' );
ok( 'https://p01-caldav.icloud.com/published/2/xyz' === $B::clean_url( 'webcal://p01-caldav.icloud.com/published/2/xyz' ), 'Apple\'s webcal:// address is read over https' );
ok( '' === $B::clean_url( 'http://example.com/cal.ics' ), 'plain http is refused' );
ok( '' === $B::clean_url( 'https://user:pass@example.com/cal.ics' ) && '' === $B::clean_url( 'https://exa mple.com/x' ) && '' === $B::clean_url( 'ftp://x/y' ) && '' === $B::clean_url( 'javascript:alert(1)' ), 'addresses with a login, spaces or another scheme are refused' );
ok( 'calendar.google.com … ic.ics' === $B::mask( 'https://calendar.google.com/calendar/ical/me%40gmail.com/private-abc123/basic.ics' ), 'only the host and the last 6 characters are shown' );
ok( $B::is_calendar( "\xEF\xBB\xBF\r\nBEGIN:VCALENDAR\r\nEND:VCALENDAR" ) && ! $B::is_calendar( '<!doctype html><html>Sign in</html>' ), 'a calendar file is told apart from a web page' );
ok( 5400 === $B::duration( 'PT1H30M' ) && 86400 === $B::duration( 'P1D' ) && 0 === $B::duration( 'nonsense' ), 'DURATION values' );

// ---------- the background read ----------
$url  = 'https://calendar.google.com/calendar/ical/me%40gmail.com/private-abc123/basic.ics';
$key  = $B::key( $url );
$GLOBALS['opts'][ $B::FEEDS ] = array( $url );
$GLOBALS['http'][ $url ]      = array( 'code' => 200, 'headers' => array(), 'body' => $ics );
$B::refresh();
$state = $GLOBALS['opts'][ $B::STATE ][ $key ];
ok( $state['ok_at'] === $GLOBALS['now'] && 0 === $state['fail_since'] && $state['count'] > 10, 'a good read saves the busy times and when it read them' );
ok( 8 === $GLOBALS['asked'][0][1]['timeout'] && 0 === $GLOBALS['asked'][0][1]['redirection'] && true === $GLOBALS['asked'][0][1]['reject_unsafe_urls'], 'the read waits 8 seconds at most, follows no redirect by itself and refuses local addresses' );
$kept = serialize( $GLOBALS['opts'][ $B::STATE ] ) . serialize( $GLOBALS['trans'] );
ok( false === strpos( $kept, 'private-abc123' ) && false === strpos( $kept, 'gmail' ) && false === stripos( $kept, 'Jane' ) && false === stripos( $kept, 'Dentist' ), 'nothing saved holds the address, a title or a person' );
$GLOBALS['asked'] = array();
$B::refresh();
ok( ! $GLOBALS['asked'], 'a second run inside 15 minutes reads nothing' );

$s = $B::summary();
ok( 'good' === $s['state'] && 'Calendar connected.' === $s['headline'] && 1 === preg_match( '/^Last read less than a minute ago, \d+ busy times in the next 60 days\.$/', $s['detail'] ), 'status: ' . $s['headline'] . ' ' . $s['detail'] );
$GLOBALS['now'] += 240;
ok( 0 === strpos( $B::summary()['detail'], 'Last read 4 minutes ago, ' ), 'status counts minutes: ' . $B::summary()['detail'] );

// Slot builder: Tuesday Nov 10, 9 to 5, 60-minute sessions, 30-minute starts.
$type = array( 'schedule' => 'default', 'length' => 60, 'buffer' => 30 );
$day  = new DateTimeImmutable( '2026-11-10 00:00', $tz );
$list = static fn( $t ) => implode( ',', array_map( static fn( $x ) => $x->format( 'H:i' ), Scout_Booking_Slots::for_day( $t, $day ) ) );
ok( '09:00,09:30,12:30' === $list( $type ), 'busy times plus the 30-minute buffer on both sides leave 9:00, 9:30 and 12:30: ' . $list( $type ) );
$type['buffer'] = 0;
ok( '09:00,09:30,10:00,12:00,12:30,13:00,15:00' === $list( $type ), 'with no buffer, sessions fit right up to busy times: ' . $list( $type ) );
ok( Scout_Booking_Slots::is_open( $type, new DateTimeImmutable( '2026-11-10 12:00', $tz ) ) && ! Scout_Booking_Slots::is_open( $type, new DateTimeImmutable( '2026-11-10 11:00', $tz ) ), 'the check before saving a booking refuses a busy time' );
Scout_Booking::$rows = array( array( 'id' => 9, 'status' => 'confirmed', 'start_at' => '2026-11-10 12:00:00', 'block_end' => '2026-11-10 13:00:00' ) );
ok( '09:00,09:30,10:00,13:00,15:00' === $list( $type ), 'confirmed bookings still block their time as before: ' . $list( $type ) );
ok( '09:00,09:30,10:00,12:00,12:30,13:00,15:00' === implode( ',', array_map( static fn( $x ) => $x->format( 'H:i' ), Scout_Booking_Slots::for_day( $type, $day, 9 ) ) ), 'moving that booking ignores it, as before' );
Scout_Booking::$rows = array();

// A failed read keeps the last busy times and says since when.
$GLOBALS['now']         += 900;
$GLOBALS['http'][ $url ] = array( 'code' => 200, 'headers' => array(), 'body' => '<!doctype html><title>Sign in</title>' );
$B::refresh();
$state = $GLOBALS['opts'][ $B::STATE ][ $key ];
ok( 'notcal' === $state['error'] && $state['fail_since'] === $GLOBALS['now'] && '09:00,09:30,10:00,12:00,12:30,13:00,15:00' === $list( $type ), 'a web page instead of a calendar is refused, and the last busy times stay' );
$s = $B::summary();
ok( 'warn' === $s['state'] && 'Couldn’t read your calendar since Nov 5, 12:19 pm.' === $s['headline'] && 'Bookings still open, but busy times may be missing.' === $s['detail'], 'look state: ' . $s['headline'] . ' ' . $s['detail'] );
$t = $B::site_test();
ok( 'good' === $t['status'], 'Site status stays quiet for the first day of failed reads' );
$GLOBALS['now']         += 86400;
$GLOBALS['http'][ $url ] = array( 'code' => 404, 'headers' => array(), 'body' => 'Not found' );
$B::refresh();
ok( 'gone' === $GLOBALS['opts'][ $B::STATE ][ $key ]['error'] && $GLOBALS['opts'][ $B::STATE ][ $key ]['fail_since'] < $GLOBALS['now'], 'a 404 is noted, and "since" keeps the first failure' );
$t = $B::site_test();
ok( 'recommended' === $t['status'] && 'Bookings can’t read your calendar' === $t['label'] && false !== strpos( $t['actions'], 'sb-busy' ), 'after 24 hours Site status gets a row that links to the address' );
ok( false === strpos( serialize( $t ), 'private-abc123' ), 'the Site status row never shows the address' );

// Redirects: only to https.
$GLOBALS['now']         += 900;
$GLOBALS['asked']        = array();
$GLOBALS['http'][ $url ] = array( 'code' => 302, 'headers' => array( 'location' => 'http://evil.example/cal.ics' ), 'body' => '' );
$B::refresh();
ok( 1 === count( $GLOBALS['asked'] ) && 'http' === $GLOBALS['opts'][ $B::STATE ][ $key ]['error'], 'a redirect to plain http is not followed' );
$GLOBALS['now']                                  += 900;
$GLOBALS['http'][ $url ]                          = array( 'code' => 301, 'headers' => array( 'location' => 'https://calendar.example/new.ics' ), 'body' => '' );
$GLOBALS['http']['https://calendar.example/new.ics'] = array( 'code' => 200, 'headers' => array(), 'body' => $ics );
$B::refresh();
ok( 0 === $GLOBALS['opts'][ $B::STATE ][ $key ]['fail_since'], 'a redirect to another https address is followed, and the status clears' );

// No address: nothing kept, nothing blocked.
unset( $GLOBALS['opts'][ $B::FEEDS ] );
$B::refresh();
ok( ! isset( $GLOBALS['trans'][ $B::CACHE ] ) && ! isset( $GLOBALS['opts'][ $B::STATE ] ) && null === $B::summary(), 'removing the last address clears its busy times and notes' );

echo $fail ? "\n$fail FAILED\n" : "\nAll booking busy-time checks passed\n";
exit( $fail ? 1 : 0 );
