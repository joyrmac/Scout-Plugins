<?php
// Scout Build's monthly update to the client: periods, the line text, the
// warnings, the address it goes to, the email body and the text version.
// No WordPress needed:  php tools/tests/site-report.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function date_i18n( $f, $t ) { return date( $f, $t ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
$GLOBALS['opt'] = array();
require __DIR__ . '/../../scout-build/includes/class-scout-hub-report.php';

$fails = 0;
function ok( $c, $w ) { global $fails; echo ( $c ? 'ok   ' : 'FAIL ' ) . $w . "\n"; $fails += $c ? 0 : 1; }

// Off by default: no saved settings means no draft is ever due.
$s = Scout_Hub_Report::settings();
ok( 'off' === $s['cadence'] && 'email' === $s['channel'] && '' === $s['to'], 'off and email by default, no address' );
ok( '' === Scout_Hub_Report::period_for( '2026-11-01', $s['cadence'] ), 'the default drafts nothing' );
$GLOBALS['opt']['scout_hub_report_settings'] = array( 'cadence' => 'weekly', 'channel' => 'fax', 'to' => 'not an email' );
$s = Scout_Hub_Report::settings();
ok( 'off' === $s['cadence'] && 'email' === $s['channel'] && '' === $s['to'], 'unknown settings fall back to off, email, no address' );

// Periods.
ok( '2026-10' === Scout_Hub_Report::period_for( '2026-11-01', 'monthly' ), 'monthly in November drafts October' );
ok( '2025-12' === Scout_Hub_Report::period_for( '2026-01-03', 'monthly' ), 'monthly in January drafts last December' );
ok( '' === Scout_Hub_Report::period_for( '2026-11-01', 'off' ), 'off drafts nothing' );
ok( '2026-Q3' === Scout_Hub_Report::period_for( '2026-10-02', 'quarterly' ), 'quarterly in October drafts Q3' );
ok( '2025-Q4' === Scout_Hub_Report::period_for( '2026-01-05', 'quarterly' ), 'quarterly in January drafts last Q4' );
ok( '' === Scout_Hub_Report::period_for( '2026-11-05', 'quarterly' ), 'quarterly outside Jan, Apr, Jul and Oct drafts nothing' );
ok( array( '2026-07', '2026-08', '2026-09' ) === Scout_Hub_Report::months( '2026-Q3' ), 'Q3 covers July to September' );
ok( array() === Scout_Hub_Report::months( '2026-13' ) && array() === Scout_Hub_Report::months( 'x' ), 'a period that is not one covers nothing' );
ok( 'October 2026' === Scout_Hub_Report::label( '2026-10' ), 'month label' );
ok( 'July to September 2026' === Scout_Hub_Report::label( '2026-Q3' ), 'quarter label' );
ok( 'Your website in October 2026' === Scout_Hub_Report::heading( '2026-10' ), 'headline for a month' );
ok( 'Your website from July to September 2026' === Scout_Hub_Report::heading( '2026-Q3' ), 'headline for a quarter' );

// The address: the typed one, else Business info's email.
ok( array( 'to' => 'pat@example.com', 'from' => 'field' ) === Scout_Hub_Report::pick_address( 'pat@example.com', 'shop@example.com' ), 'a typed address wins' );
ok( array( 'to' => 'shop@example.com', 'from' => 'business' ) === Scout_Hub_Report::pick_address( '', 'shop@example.com' ), 'blank uses Business info\'s email' );
ok( '' === Scout_Hub_Report::pick_address( '', '' )['to'], 'no address anywhere means none' );

// Lines.
$a = array( 'title' => 'Theme 1.1.8: hero fetchpriority', 'client_text' => 'Your home page photo loads faster on phones.', 'measure' => 'largest paint 3.9s to 2.4s' );
$b = array( 'title' => 'Scout Email on', 'client_text' => '', 'measure' => '' );
ok( 'Your home page photo loads faster on phones (largest paint 3.9s to 2.4s).' === Scout_Hub_Report::line_text( $a ), 'client wording with the measure' );
ok( 'Scout Email on.' === Scout_Hub_Report::line_text( $b ), 'no client wording falls back to the title' );

$lines = array( 'done' => array( $a, $b ), 'waiting' => array( array( 'title' => 't', 'client_text' => 'New home page title', 'measure' => '' ) ), 'coming' => array() );

// Warnings.
$w = Scout_Hub_Report::checks( $lines, true );
ok( 1 === $w['unworded'] && 0 === $w['names_scout'], 'warns about the line with no client wording; branded sites may name Scout' );
$w = Scout_Hub_Report::checks( $lines, false, 'A note from Scout.' );
ok( 2 === $w['names_scout'], 'white-label: a line and an opening that name Scout are both flagged' );

// The email body.
$html = Scout_Hub_Report::body_html( $lines, 'October 2026', 'Hair by Patrick McGuire', 'Patrick', '' );
ok( false !== strpos( $html, 'Hi Patrick,' ), 'greets by first name' );
ok( false !== strpos( $html, 'changed on Hair by Patrick McGuire in October 2026.' ), 'standard opening names the site and the month' );
ok( false !== strpos( $html, 'Waiting on your OK' ) && false !== strpos( $html, 'Site history' ), 'waiting section shows and says where to approve' );
ok( false === strpos( $html, 'Coming up' ), 'an empty Coming up section is left out' );
$quarter = Scout_Hub_Report::body_html( $lines, 'July to September 2026', 'X', '', '' );
ok( false !== strpos( $quarter, 'from July to September 2026.' ), 'a quarter reads "from July to September"' );
$custom = Scout_Hub_Report::body_html( $lines, 'October 2026', 'X', '', 'A busy month <b>for</b> you.' );
ok( false !== strpos( $custom, 'A busy month &lt;b&gt;for&lt;/b&gt; you.' ) && false !== strpos( $custom, 'Hi,' ), 'a custom opening is escaped; no name says Hi' );
$none = Scout_Hub_Report::body_html( array( 'done' => array(), 'waiting' => array(), 'coming' => array( $a ) ), 'October 2026', 'X', '', '' );
ok( false !== strpos( $none, 'There were no changes to the site this time.' ) && false !== strpos( $none, 'Coming up' ), 'an empty month says so and still shows what is coming' );

// The text version.
$t = Scout_Hub_Report::text( $lines, 'October 2026', 'Patrick' );
ok( 0 === strpos( $t, 'Hi Patrick, your website update for October 2026: ' ), 'text opens with the name and month' );
ok( false !== strpos( $t, '1 thing needs your OK' ), 'text counts what waits on him' );
ok( strlen( $t ) < 480, 'text stays short (' . strlen( $t ) . ' characters)' );
ok( 0 === strpos( Scout_Hub_Report::text( array( 'done' => array(), 'waiting' => array(), 'coming' => array() ), 'October 2026', '' ), 'Your website update for October 2026: no changes this time.' ), 'text with no name and no changes' );

// Our own wording: no em dashes, no exclamation points, and no Scout or Claude in what the client reads.
$all = $html . $quarter . $none . $t;
$own = str_replace( array( 'Scout Email on' ), '', $all ); // That line is the test's own title.
ok( false === strpos( $all, "\u{2014}" ) && false === strpos( $all, '!' ), 'no em dashes or exclamation points' );
ok( false === stripos( $own, 'scout' ) && false === stripos( $own, 'claude' ), 'the template itself never names Scout or Claude' );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
