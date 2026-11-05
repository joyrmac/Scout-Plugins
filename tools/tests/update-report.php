<?php
// Scout Build: the update report copied from Scout tools, Updates. Runs without WordPress:
//   php tools/tests/update-report.php
$fail = 0;
function ok( $cond, $msg ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n"; if ( ! $cond ) { $fail++; } }
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../scout-build/includes/class-scout-hub-update-report.php';
$R = 'Scout_Hub_Update_Report';

// Fixture: a client site with three updates waiting, Scout Core listed last on purpose.
$plugins = array(
	array( 'slug' => 'scout-hours', 'name' => 'Scout Hours', 'installed' => '0.3.0', 'available' => '', 'on' => true, 'status' => 'up to date' ),
	array( 'slug' => 'scout-build', 'name' => 'Scout Build', 'installed' => '0.12.4', 'available' => '0.13.0', 'on' => true, 'status' => 'waiting for your OK' ),
	array( 'slug' => 'scout-aeo', 'name' => 'Scout AI Search', 'installed' => '1.2.0', 'available' => '', 'on' => false, 'status' => 'up to date' ),
	array( 'slug' => 'scout-analytics', 'name' => 'Scout Analytics', 'installed' => '0.9.1', 'available' => '0.9.2', 'on' => true, 'status' => 'held by Scout. Fix coming, ask joy@scoutraleigh.com' ),
	array( 'slug' => 'scout-core', 'name' => 'Scout Core', 'installed' => '1.8.0', 'available' => '1.9.0', 'on' => true, 'status' => 'installs by itself Nov 7' ),
);
$health = "Scout Build site health: Hair by Patrick McGuire (https://example.test/)\nChecked 2026-11-05 14:10, plan: build\n\nBASICS\n[ok]   WordPress: 6.8. Up to date.\n[fix]  Secure connection (HTTPS): Off. The site address is not HTTPS.";
$data = array(
	'site'    => 'Hair by Patrick McGuire',
	'url'     => 'https://example.test/',
	'time'    => '2026-11-05 14:10',
	'wp'      => '6.8',
	'php'     => '8.2.10',
	'theme'   => array( 'name' => 'Patrick', 'version' => '1.4.0', 'state' => '1.4.1 ready', 'key' => 'saved', 'token' => 'github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz' ),
	'checked' => '5 minutes ago',
	'offline' => false,
	'plugins' => $plugins,
	'copy'    => '2 items to review.',
	'changes' => '3 client edits waiting to go back to the repo. Edited by owner@example.com',
	'checks'  => $R::counts( array(
		array( 'label' => 'WordPress', 'state' => 'good' ),
		array( 'label' => 'PHP', 'state' => 'warn' ),
		array( 'label' => 'Secure connection (HTTPS)', 'state' => 'bad' ),
		array( 'label' => 'Answers on pages', 'state' => 'good' ),
	) ),
	'health'  => $health,
);
$text = $R::text( $data );

// Each part, in the order the screen shows them.
$parts = array(
	'Update report: Hair by Patrick McGuire (https://example.test/)',
	'Made 2026-11-05 14:10',
	'WordPress 6.8, PHP 8.2.10, theme Patrick 1.4.0',
	'1. CHECK FOR NEW VERSIONS',
	'Last checked 5 minutes ago.',
	'2. PLUGINS (3 waiting)',
	'3. THEME',
	'Patrick 1.4.0: 1.4.1 ready',
	'Theme key: saved',
	'4. PAGE COPY',
	'2 items to review.',
	'5. BRING CHANGES BACK',
	'3 client edits waiting to go back to the repo.',
	'6. CHECK THE SITE',
	'2 ok, 1 look, 1 fix. Fix: Secure connection (HTTPS).',
	'SITE STATUS',
	'Scout Build site health: Hair by Patrick McGuire',
);
$at    = -1;
$order = true;
foreach ( $parts as $part ) {
	$pos = strpos( $text, $part );
	if ( false === $pos || $pos < $at ) {
		$order = false;
		echo "  missing or out of order: $part\n";
	}
	$at = false === $pos ? $at : $pos;
}
ok( $order, 'the report has every part, in screen order: header, steps 1 to 6, then Site status' );
ok( false !== strpos( $text, "SITE STATUS\n" . $health ), 'the Site status part is the Site status copy, word for word' );

// Plugins: waiting ones on top, Scout Core first among them, then the rest by name.
$sorted = array_column( $R::sort( $plugins ), 'slug' );
ok( array( 'scout-core', 'scout-analytics', 'scout-build', 'scout-aeo', 'scout-hours' ) === $sorted, 'waiting plugins come first, Scout Core first among them: ' . implode( ', ', $sorted ) );
$lines = array_values( array_filter( explode( "\n", $text ), static fn( $l ) => 0 === strpos( $l, '- ' ) ) );
ok( 0 === strpos( $lines[0] ?? '', '- Scout Core [scout-core] 1.8.0, new 1.9.0: installs by itself Nov 7' ), 'the first plugin line is Scout Core with its folder name, installed and new version and its state' );
ok( false !== strpos( $text, "2. PLUGINS (3 waiting)\nUpdate Scout Core first." ), 'the plugins part says to update Scout Core first' );
ok( false !== strpos( $text, '- Scout Build [scout-build] 0.12.4, new 0.13.0: waiting for your OK' ), 'a release waiting for an OK says so' );
ok( false !== strpos( $text, '- Scout AI Search [scout-aeo] (off) 1.2.0: up to date' ), 'a plugin that is off says so, with its version' );
$no_core = $plugins;
$no_core[4]['available'] = '';
ok( false === strpos( $R::text( array( 'plugins' => $no_core ) + $data ), 'Update Scout Core first.' ), 'no Scout Core line when Scout Core has nothing waiting' );

// Nothing private leaves the site: no email address, no key, anywhere in the report.
ok( ! preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text ), 'no email addresses in the report' );
ok( false === strpos( $text, 'github_pat_' ), 'the theme key never appears' );
$keyed = $R::text( array( 'health' => $health . "\nNote: ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789" ) + $data );
ok( false === strpos( $keyed, 'ghp_' ) && false !== strpos( $keyed, '[key hidden]' ), 'a key shaped string is hidden even inside the Site status part' );
ok( false !== strpos( $text, 'Fix coming, ask [email hidden]' ), 'an address in a note is replaced, the rest of the note stays' );

// Counts the way Site status marks them.
$c = $R::counts( array( array( 'label' => 'A', 'state' => 'good' ), array( 'label' => 'B', 'state' => 'warn' ), array( 'label' => 'C', 'state' => 'bad' ), array( 'label' => 'D', 'state' => 'bad' ) ) );
ok( 1 === $c['ok'] && 1 === $c['look'] && 2 === $c['fix'] && array( 'C', 'D' ) === $c['fix_names'], 'counts are ok, look and fix, with the fix rows named' );

// A site that has never checked, and one that could not reach GitHub.
$fresh = $R::text( array( 'checked' => '', 'offline' => true, 'plugins' => array() ) + $data );
ok( false !== strpos( $fresh, "Could not reach GitHub on the last check.\nNot checked yet." ), 'an unchecked, offline site says both plainly' );
ok( false !== strpos( $fresh, '2. PLUGINS (0 waiting)' ), 'no plugins means 0 waiting' );
ok( false === strpos( $text, 'Claude' ), 'the report never names Claude' );

echo $fail ? "\n$fail FAILED\n" : "\nAll update report checks passed\n";
exit( $fail ? 1 : 0 );
