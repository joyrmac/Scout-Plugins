<?php
// Scout Build 0.12.0's monthly update: periods, the line text, the email body
// and the text version. No WordPress needed:  php tools/tests/site-report.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function date_i18n( $f, $t ) { return date( $f, $t ); }
require __DIR__ . '/../../scout-build/includes/class-scout-hub-report.php';

$fails = 0;
function ok( $c, $w ) { global $fails; echo ( $c ? 'ok   ' : 'FAIL ' ) . $w . "\n"; $fails += $c ? 0 : 1; }

ok( '2026-10' === Scout_Hub_Report::period_for( '2026-11-01', 'monthly' ), 'monthly in November drafts October' );
ok( '2025-12' === Scout_Hub_Report::period_for( '2026-01-03', 'monthly' ), 'monthly in January drafts last December' );
ok( '' === Scout_Hub_Report::period_for( '2026-11-01', 'off' ), 'off drafts nothing' );
ok( '2026-Q3' === Scout_Hub_Report::period_for( '2026-10-02', 'quarterly' ), 'quarterly in October drafts Q3' );
ok( '2025-Q4' === Scout_Hub_Report::period_for( '2026-01-05', 'quarterly' ), 'quarterly in January drafts last Q4' );
ok( '' === Scout_Hub_Report::period_for( '2026-11-05', 'quarterly' ), 'quarterly outside Jan/Apr/Jul/Oct drafts nothing' );
ok( array( '2026-07', '2026-08', '2026-09' ) === Scout_Hub_Report::months( '2026-Q3' ), 'Q3 covers July to September' );
ok( 'October 2026' === Scout_Hub_Report::label( '2026-10' ), 'month label' );
ok( 'July to September 2026' === Scout_Hub_Report::label( '2026-Q3' ), 'quarter label' );

$a = array( 'title' => 'Theme 1.1.8: hero fetchpriority', 'client_text' => 'Your home page photo loads faster on phones.', 'measure' => 'largest paint 3.9s to 2.4s' );
$b = array( 'title' => 'Scout Email on', 'client_text' => '', 'measure' => '' );
ok( 'Your home page photo loads faster on phones (largest paint 3.9s to 2.4s).' === Scout_Hub_Report::line_text( $a ), 'client wording with the measure' );
ok( 'Scout Email on.' === Scout_Hub_Report::line_text( $b ), 'no client wording falls back to the title' );

$lines = array( 'done' => array( $a, $b ), 'waiting' => array( array( 'title' => 't', 'client_text' => 'New home page title', 'measure' => '' ) ), 'coming' => array() );
$html  = Scout_Hub_Report::body_html( $lines, 'October 2026', 'Hair by Patrick McGuire', 'Patrick', '' );
ok( false !== strpos( $html, 'Hi Patrick,' ), 'greets by first name' );
ok( false !== strpos( $html, 'in October 2026' ), 'standard opening names the month' );
ok( false !== strpos( $html, 'Waiting on your OK' ), 'waiting section shows' );
ok( false === strpos( $html, 'Coming up' ), 'empty coming-up section is left out' );
ok( false === strpos( $html, '—' ), 'no em dashes' );
$custom = Scout_Hub_Report::body_html( $lines, 'October 2026', 'X', '', 'A busy month <b>for</b> you.' );
ok( false !== strpos( $custom, 'A busy month &lt;b&gt;for&lt;/b&gt; you.' ) && false !== strpos( $custom, 'Hi,' ), 'custom opening is escaped; no name says Hi' );
$none = Scout_Hub_Report::body_html( array( 'done' => array(), 'waiting' => array(), 'coming' => array() ), 'October 2026', 'X', '', '' );
ok( false !== strpos( $none, 'Nothing changed on the site this time' ), 'an empty month still says something true' );

$t = Scout_Hub_Report::text( $lines, 'October 2026', 'Patrick' );
ok( 0 === strpos( $t, 'Hi Patrick, your website update for October 2026: ' ), 'text opens with the name and month' );
ok( false !== strpos( $t, '1 thing needs your OK' ), 'text counts what waits on him' );
ok( strlen( $t ) < 480, 'text stays short (' . strlen( $t ) . ' characters)' );

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit( $fails ? 1 : 0 );
